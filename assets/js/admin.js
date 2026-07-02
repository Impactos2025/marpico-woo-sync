const jQuery = window.jQuery;
//const marpico_ajax = window.marpico_ajax

jQuery(($) => {
  let syncInProgress = false;
  let syncPaused = false;
  let currentOffset = 0;
  let retryCount = 0;
  const maxRetries = 3;
  let syncStartTime = null;
  let batchStartTime = null;
  let syncMode = "batch"; // 'batch' or 'individual'

  function formatElapsedTime(startTime) {
    const elapsed = Date.now() - startTime;
    const seconds = Math.floor(elapsed / 1000);
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);

    if (hours > 0) {
      return `${hours}h ${minutes % 60}m ${seconds % 60}s`;
    } else if (minutes > 0) {
      return `${minutes}m ${seconds % 60}s`;
    } else {
      return `${seconds}s`;
    }
  }

  function getCurrentTimestamp() {
    const now = new Date();
    return now.toLocaleString("es-ES", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
    });
  }

  function addLogEntry(message, type = "info") {
    const timestamp = getCurrentTimestamp();
    const logEntry = `[${timestamp}] ${message}`;

    const logContainer = $("#marpico-logs-container");
    if (logContainer.length) {
      const logClass = `marpico-log-${type}`;
      const logHtml = `
        <div class="marpico-log-entry ${logClass}">
          <span class="marpico-log-timestamp">[${timestamp}]</span>
          <span class="marpico-log-message">${message}</span>
        </div>
      `;
      logContainer.prepend(logHtml);

      if (logContainer.children().length > 100) {
        logContainer.children().slice(100).remove();
      }

      if (
        logContainer.scrollTop() + logContainer.innerHeight() >=
        logContainer[0].scrollHeight - 50
      ) {
        logContainer.scrollTop(logContainer[0].scrollHeight);
      }
    }

    console.log(`[v0] ${logEntry}`);
  }

  function initializeModernInterface() {
    $(".marpico-sync-option").on("click", function () {
      $(".marpico-sync-option").removeClass("active");
      $(this).addClass("active");

      const mode = $(this).data("mode");
      syncMode = mode;

      if (mode === "individual") {
        $("#individual-sync-form")
          .removeClass("marpico-hidden")
          .addClass("marpico-fade-in");
        $("#batch-sync-form").addClass("marpico-hidden");
        addLogEntry("Modo individual seleccionado", "info");
      } else {
        $("#batch-sync-form")
          .removeClass("marpico-hidden")
          .addClass("marpico-fade-in");
        $("#individual-sync-form").addClass("marpico-hidden");
        addLogEntry("Modo por lotes seleccionado", "info");
      }
    });

    $("#sync-individual-product").on("click", (e) => {
      e.preventDefault();
      const productCode = $("#product-code-input").val().trim();

      if (!productCode) {
        addLogEntry("Error: Debe ingresar un código de producto", "error");
        return;
      }

      syncIndividualProduct(productCode);
    });

    updateStats();
  }

  function syncIndividualProduct(productCode) {
    const btn = $("#sync-individual-product");
    btn.prop("disabled", true).text("Sincronizando...");

    syncStartTime = Date.now();
    addLogEntry(
      `Iniciando sincronización del producto: ${productCode}`,
      "info",
    );

    $.post(
      marpico_ajax.ajax_url,
      {
        action: "marpico_sync_product_individual",
        security: marpico_ajax.nonce,
        product_code: productCode,
      },
      (resp) => {
        const elapsed = formatElapsedTime(syncStartTime);

        if (resp.success) {
          const message = `Producto ${productCode} sincronizado exitosamente en ${elapsed}`;
          addLogEntry(message, "success");
          $("#individual-sync-status").html(
            `<span class="marpico-log-success">✓ ${message}</span>`,
          );
          $("#product-code-input").val("");
          updateStats();
        } else {
          const message = `Error sincronizando producto ${productCode}: ${
            resp.data || "Error desconocido"
          }`;
          addLogEntry(message, "error");
          $("#individual-sync-status").html(
            `<span class="marpico-log-error">❌ ${message}</span>`,
          );
        }
        btn.prop("disabled", false).text("Sincronizar Producto");
      },
    ).fail((xhr) => {
      const elapsed = formatElapsedTime(syncStartTime);
      const message = `Error de conexión sincronizando producto ${productCode} después de ${elapsed}`;
      addLogEntry(message, "error");
      $("#individual-sync-status").html(
        `<span class="marpico-log-error">❌ ${message}</span>`,
      );
      btn.prop("disabled", false).text("Sincronizar Producto");
    });
  }

  function updateStats() {
    $.post(
      marpico_ajax.ajax_url,
      {
        action: "marpico_get_sync_stats",
        security: marpico_ajax.nonce,
      },
      (resp) => {
        if (resp.success) {
          const stats = resp.data;
          $("#total-products-stat").text(stats.total_products || "0");
          $("#last-sync-stat").text(stats.last_sync || "Nunca");
          $("#sync-errors-stat").text(stats.sync_errors || "0");
          $("#api-status-stat").text(stats.api_status || "Desconocido");
        }
      },
    );
  }

  // ===================== Sincronización en segundo plano =====================
  // El servidor (Action Scheduler) encadena los lotes; el navegador solo lanza
  // el trabajo y consulta el progreso. Sobrevive a cambios/cierre de pestaña.
  const SYNC = (function () {
    let pollTimer = null;
    const POLL_MS = 4000;

    function statusEl(provider) {
      if (provider === "cdo") return $("#cdo-sync-status-batch");
      if (provider === "beststock") return $("#api-sync-status");
      if (provider === "price") return $("#marpico-price-status");
      return $("#marpico-sync-status-batch");
    }
    function providerLabel(p) {
      if (p === "cdo") return "CDO";
      if (p === "beststock") return "BestStock";
      if (p === "price") return "Ajuste de precios";
      return "Marpico";
    }
    // Bloquea el selector de servicio mientras un job está activo (no se puede
    // sincronizar otro proveedor a la vez). Activo = queued|running|paused; los
    // estados terminales (completed|failed|canceled) lo liberan.
    function applySelectorLock(job) {
      const sel = $("#sync-service-selector");
      if (!sel.length) return;
      const active =
        job && ["queued", "running", "paused"].indexOf(job.status) >= 0;
      if (active) {
        // Solo fijar el selector si el job es de un proveedor real (no un job de precios).
        if (
          job.provider &&
          job.provider !== "price" &&
          sel.val() !== job.provider
        ) {
          sel.val(job.provider).trigger("change");
        }
        sel.prop("disabled", true).attr(
          "title",
          "Hay un proceso en curso (" +
            providerLabel(job.provider) +
            "). Cancélalo para cambiar de servicio.",
        );
      } else {
        sel.prop("disabled", false).removeAttr("title");
      }
    }
    function escapeHtml(s) {
      return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
      });
    }
    function ajax(action, extra, cb) {
      $.post(
        marpico_ajax.ajax_url,
        Object.assign({ action: action, security: marpico_ajax.nonce }, extra || {}),
        cb,
      );
    }

    function render(job) {
      applySelectorLock(job);
      if (!job || job.status === "idle") return;
      const el = statusEl(job.provider);
      if (!el.length) return;

      const pct = job.percent || 0;
      const running = job.status === "running" || job.status === "queued";
      let controls = "";
      if (running) {
        controls =
          '<button class="marpico-btn sync-pause" style="margin-right:8px;">Pausar</button>' +
          '<button class="marpico-btn sync-cancel" style="background:#ef4444;color:#fff;">Cancelar</button>';
      } else if (job.status === "paused" || job.status === "failed") {
        controls =
          '<button class="marpico-btn sync-resume" style="margin-right:8px;background:#0073aa;color:#fff;">Reanudar</button>' +
          '<button class="marpico-btn sync-cancel" style="background:#ef4444;color:#fff;">Cancelar</button>';
      }

      el.html(
        '<div class="marpico-progress-container">' +
          '<div class="marpico-progress-bar"><div class="marpico-progress-fill" style="width:' +
          pct +
          '%"></div></div>' +
          '<div class="marpico-progress-text">' +
          pct +
          "% — " +
          escapeHtml(job.message || "") +
          " (" +
          (job.processed || 0) +
          "/" +
          (job.total || 0) +
          ")</div>" +
          '<div class="sync-controls" style="margin-top:12px;">' +
          controls +
          "</div>" +
        "</div>",
      );
    }

    function tick() {
      ajax("marpico_sync_status", {}, function (resp) {
        if (!resp || !resp.success) return;
        const job = resp.data;
        if (!job || job.status === "idle") {
          stopPoll();
          return;
        }
        render(job);
        if (["running", "queued"].indexOf(job.status) < 0) {
          stopPoll();
          if (job.status === "completed") {
            addLogEntry("✓ " + (job.message || "Sincronización completada"), "success");
          } else if (job.status === "failed") {
            addLogEntry("❌ " + (job.last_error || job.message || "Error"), "error");
          } else if (job.status === "canceled") {
            addLogEntry("⚠ Sincronización cancelada", "warning");
          }
          updateStats();
        }
      });
    }
    function startPoll() {
      stopPoll();
      pollTimer = setInterval(tick, POLL_MS);
    }
    function stopPoll() {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }

    function start(provider, extra) {
      ajax("marpico_sync_start", Object.assign({ provider: provider }, extra || {}), function (resp) {
        if (!resp || !resp.success) {
          const msg = resp && resp.data ? resp.data : "No se pudo iniciar la sincronización";
          statusEl(provider).html('<span style="color:#ef4444;">❌ ' + escapeHtml(msg) + "</span>");
          addLogEntry(msg, "error");
          return;
        }
        addLogEntry("Sincronización " + providerLabel(provider) + " iniciada en segundo plano", "info");
        render(resp.data);
        startPoll();
      });
    }

    // Lanza "Aplicar ahora" (job de precios en segundo plano). `extra` lleva la config del formulario.
    function startPrice(extra) {
      ajax("marpico_price_apply_start", extra || {}, function (resp) {
        if (!resp || !resp.success) {
          const msg = resp && resp.data ? resp.data : "No se pudo iniciar el ajuste de precios";
          statusEl("price").html('<span style="color:#ef4444;">❌ ' + escapeHtml(msg) + "</span>");
          addLogEntry(msg, "error");
          return;
        }
        addLogEntry("Ajuste de precios iniciado en segundo plano", "info");
        render(resp.data);
        startPoll();
      });
    }

    // Controles delegados (sobreviven a los re-render del panel).
    $(document).on("click", ".sync-pause", function (e) {
      e.preventDefault();
      ajax("marpico_sync_pause", {}, function (r) { if (r && r.success) render(r.data); });
    });
    $(document).on("click", ".sync-resume", function (e) {
      e.preventDefault();
      ajax("marpico_sync_resume", {}, function (r) { if (r && r.success) { render(r.data); startPoll(); } });
    });
    $(document).on("click", ".sync-cancel", function (e) {
      e.preventDefault();
      if (!confirm("¿Cancelar la sincronización en curso?")) return;
      ajax("marpico_sync_cancel", {}, function (r) { if (r && r.success) { render(r.data); stopPoll(); } });
    });

    // Al cargar la página: si hay un trabajo vivo, mostrarlo y reanudar el polling.
    function init() {
      ajax("marpico_sync_status", {}, function (resp) {
        if (!resp || !resp.success) return;
        const job = resp.data;
        if (!job || job.status === "idle") return;
        const sel = $("#sync-service-selector");
        if (sel.length && job.provider) sel.val(job.provider).trigger("change");
        render(job);
        if (["running", "queued", "paused"].indexOf(job.status) >= 0) startPoll();
      });
    }

    return { start: start, startPrice: startPrice, init: init };
  })();

  // ===================== Ajuste de precios: guardar / aplicar =====================
  function priceFormData() {
    return {
      monto: $("#price-monto").val() || 0,
      porcentaje: $("#price-porcentaje").val() || 0,
      excluir_cats: $('input[name="excluir_cats[]"]:checked').map(function () { return this.value; }).get(),
      marcas_porcentaje: $('input[name="marcas_porcentaje[]"]:checked').map(function () { return this.value; }).get(),
      excluir_marcas: $('input[name="excluir_marcas[]"]:checked').map(function () { return this.value; }).get(),
    };
  }

  $("#price-save").on("click", function (e) {
    e.preventDefault();
    const btn = $(this).prop("disabled", true).text("Guardando…");
    $.post(
      marpico_ajax.ajax_url,
      Object.assign({ action: "marpico_price_save", security: marpico_ajax.nonce }, priceFormData()),
      function (resp) {
        $("#price-save-msg").html(
          resp && resp.success
            ? '<span style="color:#16a34a;">✓ Configuración guardada.</span>'
            : '<span style="color:#ef4444;">❌ ' + (resp && resp.data ? resp.data : "Error") + "</span>",
        );
      },
    ).always(function () {
      btn.prop("disabled", false).text("Guardar configuración");
    });
  });

  $("#price-apply").on("click", function (e) {
    e.preventDefault();
    if (!confirm("¿Aplicar el ajuste de precios a todo el catálogo? Se guardará la configuración actual y correrá en segundo plano.")) return;
    // Guarda la config del formulario y lanza el job de precios.
    SYNC.startPrice(priceFormData());
  });

  // Marca excluida (③) → deshabilita su casilla en "recibe %" (②).
  $(document).on("change", ".price-excl-brand", function () {
    const id = $(this).data("brand");
    const pct = $('#price-marcas-pct input[value="' + id + '"]');
    const wrap = $(".pc-brand-pct-" + id);
    if (this.checked) {
      pct.prop("checked", false).prop("disabled", true);
      wrap.css("opacity", 0.5);
    } else {
      pct.prop("disabled", false);
      wrap.css("opacity", 1);
    }
  });

  // ===================== Programación =====================
  function schFreqVisibility($row) {
    const f = $row.find(".sch-freq").val();
    $row.find(".sch-weekday-wrap").toggle(f === "weekly");
    $row.find(".sch-hour-wrap").toggle(f === "daily" || f === "weekly");
  }
  $(".sch-row").each(function () { schFreqVisibility($(this)); });
  $(document).on("change", ".sch-freq", function () {
    schFreqVisibility($(this).closest(".sch-row"));
  });

  $("#schedule-save").on("click", function (e) {
    e.preventDefault();
    const schedules = {};
    $(".sch-row").each(function () {
      const p = $(this).data("provider");
      schedules[p] = {
        enabled: $(this).find(".sch-enabled").is(":checked") ? 1 : 0,
        frequency: $(this).find(".sch-freq").val(),
        hour: $(this).find(".sch-hour").val(),
        weekday: $(this).find(".sch-weekday").val(),
      };
    });
    const btn = $(this).prop("disabled", true).text("Guardando…");
    $.post(
      marpico_ajax.ajax_url,
      { action: "marpico_schedule_save", security: marpico_ajax.nonce, schedules: schedules },
      function (resp) {
        if (resp && resp.success) {
          $("#schedule-msg").html('<span style="color:#16a34a;">✓ Programación guardada.</span>');
          $.each(resp.data.next || {}, function (p, val) {
            $('.sch-row[data-provider="' + p + '"] .sch-next').text(val || "—");
          });
        } else {
          $("#schedule-msg").html('<span style="color:#ef4444;">❌ ' + (resp && resp.data ? resp.data : "Error") + "</span>");
        }
      },
    ).always(function () {
      btn.prop("disabled", false).text("Guardar programación");
    });
  });

  $(document).on("click", ".sch-run", function (e) {
    e.preventDefault();
    const p = $(this).closest(".sch-row").data("provider");
    const btn = $(this).prop("disabled", true).text("Iniciando…");
    $.post(
      marpico_ajax.ajax_url,
      { action: "marpico_schedule_run_now", security: marpico_ajax.nonce, provider: p },
      function (resp) {
        $("#schedule-msg").html(
          resp && resp.success
            ? '<span style="color:#16a34a;">✓ Sincronización ' + p + ' iniciada. Ve a "Sincronización" para ver el progreso.</span>'
            : '<span style="color:#ef4444;">❌ ' + (resp && resp.data ? resp.data : "Error") + "</span>",
        );
      },
    ).always(function () {
      btn.prop("disabled", false).text("Ejecutar ahora");
    });
  });

  // Lanzar sincronización Marpico (catálogo completo) en segundo plano.
  $("#sync-products-batch").on("click", function (e) {
    e.preventDefault();
    SYNC.start("marpico", {});
  });

  // ===================== Registro de actividad =====================
  const LOGS = (function () {
    let entries = [];
    let level = "all";
    let timer = null;

    function levelColor(l) {
      return l === "error" ? "#ef4444" : l === "warning" ? "#d97706" : l === "success" ? "#16a34a" : "#2271b1";
    }
    function esc(s) {
      return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
      });
    }

    function render() {
      const q = ($("#log-search").val() || "").toLowerCase();
      const rows = entries.filter(function (e) {
        if (level !== "all" && e.level !== level) return false;
        if (q && (e.msg || "").toLowerCase().indexOf(q) < 0 && (e.ctx || "").toLowerCase().indexOf(q) < 0) return false;
        return true;
      });
      const c = $("#marpico-logs-container");
      if (!rows.length) {
        c.html('<div style="color:#888;padding:12px;">Sin registros que coincidan.</div>');
        return;
      }
      c.html(
        rows.map(function (e) {
          return (
            '<div class="marpico-log-entry" style="border-left:3px solid ' + levelColor(e.level) +
            ';padding:6px 10px;margin-bottom:2px;font-size:13px;">' +
            '<span style="color:#888;">[' + esc(e.t) + "]</span> " +
            (e.ctx ? '<span style="color:' + levelColor(e.level) + ';font-weight:600;">' + esc(e.ctx) + "</span> " : "") +
            '<span class="marpico-log-message">' + esc(e.msg) + "</span></div>"
          );
        }).join(""),
      );
    }

    function applyCounts(counts) {
      $(".log-count").each(function () {
        const k = $(this).data("c");
        $(this).text(counts && counts[k] != null ? counts[k] : 0);
      });
    }

    function load() {
      $.post(
        marpico_ajax.ajax_url,
        { action: "marpico_get_logs", security: marpico_ajax.nonce },
        function (resp) {
          if (resp && resp.success) {
            entries = resp.data.entries || [];
            applyCounts(resp.data.counts);
            render();
          }
        },
      );
    }

    function startAuto() {
      stopAuto();
      if ($("#log-autorefresh").is(":checked")) timer = setInterval(load, 5000);
    }
    function stopAuto() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    $(document).on("click", ".log-filter", function () {
      $(".log-filter").removeClass("active");
      $(this).addClass("active");
      level = $(this).data("level");
      render();
    });
    $(document).on("input", "#log-search", render);
    $(document).on("click", "#log-refresh", function (e) { e.preventDefault(); load(); });
    $(document).on("change", "#log-autorefresh", startAuto);
    $(document).on("click", "#log-clear", function (e) {
      e.preventDefault();
      if (!confirm("¿Vaciar el registro de actividad?")) return;
      $.post(marpico_ajax.ajax_url, { action: "marpico_clear_logs", security: marpico_ajax.nonce }, function (resp) {
        if (resp && resp.success) { entries = []; applyCounts(resp.data.counts); render(); }
      });
    });
    $(document).on("click", "#log-export", function (e) {
      e.preventDefault();
      const txt = entries
        .map(function (x) { return "[" + x.t + "] " + (x.ctx ? x.ctx + " " : "") + (x.level || "").toUpperCase() + ": " + x.msg; })
        .join("\n");
      const blob = new Blob([txt], { type: "text/plain" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "marpico-registro.txt";
      a.click();
      URL.revokeObjectURL(a.href);
    });

    // Auto-refresh solo mientras la pestaña Registro está a la vista.
    $(document).on("click", ".marpico-nav-item", function () {
      if ($(this).data("section") === "logs") { load(); startAuto(); }
      else stopAuto();
    });

    return { load: load };
  })();

  $(document).ready(function () {
    SYNC.init();
    LOGS.load(); // precargar conteos/entradas
  });

  $(document).ready(() => {
    initializeModernInterface();

    setInterval(updateStats, 30000);
  });

  // --- Cargar categorías BestStock al iniciar ---
  $(document).ready(function () {
    const $category = $("#beststock-category");
    const $subcategory = $("#beststock-subcategory");

    // Deshabilitamos select hijo mientras carga
    $subcategory.prop("disabled", true);

    // Llenamos select de categorías padre desde la API
    $.post(
      marpico_ajax.ajax_url,
      { action: "get_beststock_categories", security: marpico_ajax.nonce },
      function (resp) {
        if (resp.success && Array.isArray(resp.data)) {
          let options = '<option value="">Selecciona categoría</option>';
          resp.data.forEach((cat) => {
            // <-- aquí usamos resp.data
            options += `<option value="${cat.id}" data-sub='${JSON.stringify(
              cat.subcategorias,
            )}'>${cat.name}</option>`;
          });
          $category.html(options).prop("disabled", false);
        } else {
          $category.html('<option value="">Error cargando categorías</option>');
        }
      },
    ).fail(function () {
      $category.html('<option value="">Error cargando categorías</option>');
    });

    // Cuando cambia categoría principal
    $category.on("change", function () {
      const selected = $(this).find("option:selected");
      let subs = selected.data("sub"); // puede ser undefined

      if (!subs) {
        subs = []; // default a array vacío si no existe
      } else if (typeof subs === "string") {
        try {
          subs = JSON.parse(subs);
        } catch (e) {
          subs = [];
          console.error("Error parseando subcategorias:", e);
        }
      }

      if (subs.length > 0) {
        let options = '<option value="">Selecciona subcategoría</option>';
        subs.forEach((sub) => {
          options += `<option value="${sub.id}">${sub.name}</option>`;
        });
        $subcategory.html(options).prop("disabled", false); // show() ya no es necesario
      } else {
        $subcategory.html("").prop("disabled", true);
      }
    });
  });

  // --- BestStock: Sincronización por Categoría en Batches ---
  // --- BestStock: lanzar sincronización por categoría en segundo plano ---
  $("#sync-beststock-category").on("click", function (e) {
    e.preventDefault();

    const categoryId = $("#beststock-subcategory").val().trim();
    const parentId = $("#wc-category").val();
    const childId =
      $("#wc-category-child").length && $("#wc-category-child").val()
        ? $("#wc-category-child").val()
        : "";

    if (!categoryId) {
      addLogEntry("Error: Debe ingresar un ID de categoría para BestStock", "error");
      return;
    }
    if (!parentId) {
      addLogEntry("Error: Debe seleccionar una categoría de WooCommerce", "error");
      return;
    }

    SYNC.start("beststock", {
      category_id: categoryId,
      wc_category_parent: parentId,
      wc_category_child: childId,
      batch_size: 1,
    });
  });

  // --- Cargar subcategorías dinámicamente ---
  $("#wc-category").on("change", function () {
    const parentId = $(this).val();
    //$("#wc-category-child").remove(); // limpiamos hijos previos
    console.log("Padre seleccionado:", parentId);

    if (!parentId) {
      $("#child-category-wrapper").hide(); // esconder bloque completo
      $("#wc-category-child").empty();
      return;
    }

    // Mostrar estado de carga
    $("#wc-category-child")
      .html('<option value="">Cargando subcategorías...</option>')
      .prop("disabled", true);
    $("#child-category-wrapper").show();

    $.post(
      marpico_ajax.ajax_url,
      {
        action: "get_child_categories",
        security: marpico_ajax.nonce,
        parent_id: parentId,
      },
      function (resp) {
        console.log("Respuesta hijos:", resp);

        if (resp.success && resp.data.length > 0) {
          let options = '<option value="">Selecciona subcategoría</option>';
          resp.data.forEach((cat) => {
            options += `<option value="${cat.id}">${cat.name}</option>`;
          });
          $("#wc-category-child").html(options).prop("disabled", false);
          $("#child-category-wrapper").show();
        } else {
          $("#child-category-wrapper").hide();
          $("#wc-category-child").empty();
        }
      },
    ).fail(function (xhr) {
      console.error("Error AJAX hijos:", xhr);
    });
  });

  console.log("Script cargado correctamente");

  // --- CDO: lanzar sincronización (catálogo completo) en segundo plano ---
  $("#test-provider").on("click", function (e) {
    e.preventDefault();
    SYNC.start("cdo", {});
  });
});
