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
      return $("#marpico-sync-status-batch");
    }
    function providerLabel(p) {
      return p === "cdo" ? "CDO" : p === "beststock" ? "BestStock" : "Marpico";
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
        if (job.provider && sel.val() !== job.provider) {
          sel.val(job.provider).trigger("change");
        }
        sel.prop("disabled", true).attr(
          "title",
          "Hay una sincronización en curso (" +
            providerLabel(job.provider) +
            "). Cancélala para cambiar de servicio.",
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

    return { start: start, init: init };
  })();

  // Lanzar sincronización Marpico (catálogo completo) en segundo plano.
  $("#sync-products-batch").on("click", function (e) {
    e.preventDefault();
    SYNC.start("marpico", {});
  });

  $(document).ready(function () { SYNC.init(); });

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

  $("#marpico_aplicar_aumento").on("click", function () {
    console.log("Botón clickeado");

    const incremento = parseFloat($("#marpico_precio_incremento").val());
    const marca = parseInt($("#marpico_marca").val()); // NUEVO: marca seleccionada
    const excluidas = $("input[name='excluded_categories[]']:checked")
      .map(function () {
        return $(this).val();
      })
      .get();

    console.log("Incremento:", incremento);
    console.log("Marca:", marca);
    console.log("Categorías excluidas:", excluidas);

    if (!incremento || incremento === 0) {
      alert("Por favor ingresa un valor válido para el aumento.");
      return;
    }

    if (!marca || marca === 0) {
      alert("Por favor selecciona una marca.");
      return;
    }

    // Mostrar mensaje de carga
    const boton = $(this);
    boton.prop("disabled", true).text("Aplicando aumento...");
    $("#marpico_status_msg").remove();
    boton.after(
      "<p id='marpico_status_msg'> Aplicando aumento, por favor espera...</p>",
    );

    $.ajax({
      url: marpico_ajax.ajax_url,
      type: "POST",
      dataType: "json",
      data: {
        action: "marpico_aplicar_aumento", // coincide con PHP
        security: marpico_ajax.nonce,
        incremento: incremento, // monto fijo
        marca: marca, // ID de la marca
        categoriasExcluidas: excluidas, // array de categorías
      },
      beforeSend: function () {
        console.log("Enviando petición AJAX...");
      },
      success: function (response) {
        console.log("Respuesta del servidor:", response);
        alert("Ajuste completado: " + response.data);

        // Limpiar campos después de aplicar
        $("#marpico_precio_incremento").val("");
        $("#marpico_marca").val("");
        $("input[name='excluded_categories[]']").prop("checked", false);
      },
      error: function (xhr, status, error) {
        console.error("Error en AJAX:", error);
        alert("Hubo un error al aplicar el aumento. Revisa la consola.");
      },
      complete: function () {
        // Restaurar botón y quitar mensaje
        boton.prop("disabled", false).text("Aplicar aumento");
        $("#marpico_status_msg").text("Proceso completado");
        setTimeout(() => $("#marpico_status_msg").fadeOut(), 2000);
      },
    });
  });

  $(document).on("click", "#marpico_aplicar_aumento_marca", function () {
    console.log("Botón aumento por marca clickeado");

    const porcentaje = parseFloat($("#marpico_porcentaje_incremento").val());
    const marca = $("#marpico_marca_select").val();
    const excluidas = $("input[name='excluded_categories_brand[]']:checked")
      .map(function () {
        return $(this).val();
      })
      .get();

    const excluidasMarcas = $("input[name='excluded_brands[]']:checked")
      .map(function () {
        return $(this).val();
      })
      .get();

    console.log("Porcentaje:", porcentaje);
    console.log("Marca:", marca);
    console.log("Categorías excluidas:", excluidas);
    console.log("Marcas excluidas:", excluidasMarcas);

    if (!porcentaje || porcentaje === 0) {
      alert("Por favor ingresa un porcentaje válido.");
      return;
    }

    if (!marca) {
      alert("Debes seleccionar una marca.");
      return;
    }

    const boton = $(this);
    boton.prop("disabled", true).text("Aplicando aumento...");

    $("#marpico_status_msg_marca").remove();

    boton.after(
      "<p id='marpico_status_msg_marca'>Aplicando aumento por marca, por favor espera...</p>",
    );

    $.ajax({
      url: marpico_ajax.ajax_url,
      type: "POST",
      dataType: "json",
      data: {
        action: "marpico_aplicar_aumento_marca",
        security: marpico_ajax.nonce,
        porcentaje: porcentaje,
        marca: marca,
        categoriasExcluidas: excluidas,
        marcasExcluidas: excluidasMarcas,
      },

      beforeSend: function () {
        console.log("Enviando petición AJAX aumento por marca...");
      },

      success: function (response) {
        console.log("Respuesta del servidor:", response);

        alert("Ajuste por marca completado: " + response.data);

        // limpiar campos
        $("#marpico_porcentaje_incremento").val("");
        $("#marpico_marca_select").val("");
        $("input[name='excluded_categories[]']").prop("checked", false);
      },

      error: function (xhr, status, error) {
        console.error("Error en AJAX:", error);
        console.log(xhr.responseText);
        alert("Hubo un error al aplicar el aumento.");
      },

      complete: function () {
        boton.prop("disabled", false).text("Aplicar aumento por marca");

        $("#marpico_status_msg_marca").text("Proceso completado");

        setTimeout(() => $("#marpico_status_msg_marca").fadeOut(), 2000);
      },
    });
  });

  // --- CDO: lanzar sincronización (catálogo completo) en segundo plano ---
  $("#test-provider").on("click", function (e) {
    e.preventDefault();
    SYNC.start("cdo", {});
  });
});
