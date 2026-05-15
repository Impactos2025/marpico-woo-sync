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

  $("#sync-products-batch").on("click", function (e) {
    e.preventDefault();
    var btn = $(this);

    if (syncInProgress) {
      if (syncPaused) {
        syncPaused = false;
        btn.text("Pausar Sincronización");
        addLogEntry("Sincronización reanudada", "info");
        resumeSync();
      } else {
        syncPaused = true;
        btn.text("Reanudar Sincronización");
        addLogEntry("Sincronización pausada", "warning");
      }
      return;
    }

    syncStartTime = Date.now();
    syncInProgress = true;
    syncPaused = false;
    currentOffset = 0;
    retryCount = 0;

    btn.text("Pausar Sincronización").addClass("marpico-btn-secondary");
    addLogEntry("Iniciando sincronización por lotes de 10 productos", "info");

    $("#marpico-sync-status-batch").html(`
      <div class="marpico-progress-container">
        <div class="marpico-progress-bar">
          <div class="marpico-progress-fill" style="width: 0%"></div>
        </div>
        <div class="marpico-progress-text">Iniciando sincronización...</div>
        <div class="sync-controls">
          <button id="cancel-sync" class="marpico-btn" style="background: #ef4444; color: white;">Cancelar Sincronización</button>
        </div>
      </div>
    `);

    $("#cancel-sync").on("click", () => {
      cancelSync();
    });

    var totalProcessed = 0;
    var totalProducts = 0;
    var batchSize = 10;

    function cancelSync() {
      syncInProgress = false;
      syncPaused = false;
      const elapsedTime = formatElapsedTime(syncStartTime);
      const message = `Sincronización cancelada. Procesados ${totalProcessed} productos en ${elapsedTime}`;

      $("#marpico-sync-status-batch").html(
        `<span style="color: orange;">⚠ ${message}</span>`,
      );
      addLogEntry(message, "info");
      btn
        .prop("disabled", false)
        .text("Sincronizar Productos por Lotes")
        .removeClass("marpico-btn-secondary");
    }

    function resumeSync() {
      if (!syncPaused && syncInProgress) {
        syncBatch(currentOffset);
      }
    }

    function syncBatch(offset) {
      if (syncPaused || !syncInProgress) {
        return;
      }

      currentOffset = offset;
      batchStartTime = Date.now();

      console.log(
        `[v0] [${getCurrentTimestamp()}] Iniciando lote en offset: ${offset}`,
      );
      var currentPercentage =
        totalProducts > 0
          ? Math.round((totalProcessed / totalProducts) * 100)
          : 0;
      $(".marpico-progress-text").text(
        `${currentPercentage}% - Procesando lote desde posición ${offset}...`,
      );

      $.post(
        marpico_ajax.ajax_url,
        {
          action: "marpico_sync_products_batch",
          security: marpico_ajax.nonce,
          offset: offset,
          batch_size: batchSize,
        },
        (resp) => {
          const batchElapsed = formatElapsedTime(batchStartTime);
          console.log(
            `[v0] [${getCurrentTimestamp()}] Respuesta recibida en ${batchElapsed}:`,
            resp,
          );

          if (resp.success) {
            retryCount = 0;
            var data = resp.data;
            totalProcessed += data.processed;
            totalProducts = data.total;

            var percentage = Math.round((totalProcessed / totalProducts) * 100);

            $(".marpico-progress-fill")
              .css("width", percentage + "%")
              .get(0).offsetHeight;
            $(".marpico-progress-text")
              .text(
                `${percentage}% - Procesados ${totalProcessed} de ${totalProducts} productos`,
              )
              .get(0).offsetHeight;

            const logMessage = `Lote completado: ${data.processed} productos en ${batchElapsed} (${percentage}% total)`;
            addLogEntry(logMessage, "success");
            console.log(`[v0] [${getCurrentTimestamp()}] ${logMessage}`);

            updateStats();

            if (data.has_more && syncInProgress && !syncPaused) {
              setTimeout(() => {
                syncBatch(data.next_offset);
              }, 800);
            } else if (!data.has_more) {
              syncInProgress = false;
              const totalElapsed = formatElapsedTime(syncStartTime);
              const completionMessage = `✓ Sincronización completada: ${totalProcessed} productos procesados en ${totalElapsed}`;

              console.log(
                `[v0] [${getCurrentTimestamp()}] ${completionMessage}`,
              );
              addLogEntry(completionMessage, "success");

              $("#marpico-sync-status-batch").html(
                `<div class="marpico-log-success" style="padding: 16px; text-align: center; font-weight: 600;">${completionMessage}</div>`,
              );
              btn
                .prop("disabled", false)
                .text("Sincronizar Productos por Lotes")
                .removeClass("marpico-btn-secondary");
              updateStats();
            }
          } else {
            const errorMessage = `Error en lote (offset ${offset}): ${
              resp.data || "Error desconocido"
            }`;
            console.log(`[v0] [${getCurrentTimestamp()}] ${errorMessage}`);
            addLogEntry(errorMessage, "error");
            handleSyncError(resp.data || "Error desconocido", offset);
          }
        },
      ).fail((xhr) => {
        const batchElapsed = formatElapsedTime(batchStartTime);
        let errorMsg = "Error de conexión";
        if (xhr.status === 0) {
          errorMsg = "Sin conexión a internet o servidor no disponible";
        } else if (xhr.status === 500) {
          errorMsg = "Error interno del servidor";
        } else if (xhr.status === 504) {
          errorMsg = "Timeout del servidor";
        }

        const fullErrorMsg = `${errorMsg} después de ${batchElapsed} (offset ${offset})`;
        console.log(
          `[v0] [${getCurrentTimestamp()}] Error AJAX: ${xhr.status} ${
            xhr.statusText
          } - ${fullErrorMsg}`,
        );
        addLogEntry(fullErrorMsg, "error");
        handleSyncError(errorMsg, offset);
      });
    }

    function handleSyncError(errorMsg, offset) {
      retryCount++;

      if (retryCount <= maxRetries) {
        const retryMessage = `Reintentando lote ${retryCount}/${maxRetries} en 5 segundos (offset ${offset})`;
        addLogEntry(retryMessage, "info");

        $(".marpico-progress-text").html(`
          Error: ${errorMsg}<br>
          <div class="retry-info">${retryMessage}</div>
        `);

        setTimeout(() => {
          if (syncInProgress && !syncPaused) {
            syncBatch(offset);
          }
        }, 5000);
      } else {
        syncInProgress = false;
        const elapsedTime = formatElapsedTime(syncStartTime);
        const finalErrorMsg = `Error después de ${maxRetries} intentos: ${errorMsg}. Procesados ${totalProcessed} productos en ${elapsedTime}`;

        console.log(`[v0] [${getCurrentTimestamp()}] ${finalErrorMsg}`);
        addLogEntry(finalErrorMsg, "error");

        $("#marpico-sync-status-batch").html(`
          <span style="color: red;">❌ ${finalErrorMsg}</span><br>
          <button id="resume-from-error" class="marpico-btn" style="margin-top: 10px; background: #0073aa; color: white;">Continuar desde donde se quedó</button>
        `);

        $("#resume-from-error").on("click", function () {
          retryCount = 0;
          syncInProgress = true;
          syncPaused = false;
          syncStartTime = Date.now(); // Reiniciar tiempo
          btn.text("Pausar Sincronización").addClass("marpico-btn-secondary");
          addLogEntry("Reanudando sincronización desde error", "info");
          $(this)
            .parent()
            .html(
              '<div class="marpico-progress-text">Reanudando sincronización...</div>',
            );
          setTimeout(() => {
            syncBatch(currentOffset);
          }, 1000);
        });

        btn
          .prop("disabled", false)
          .text("Sincronizar Productos por Lotes")
          .removeClass("marpico-btn-secondary");
      }
    }
    syncBatch(0);
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
  $("#sync-beststock-category").on("click", function (e) {
    e.preventDefault();

    const categoryId = $("#beststock-subcategory").val().trim();
    const parentId = $("#wc-category").val();
    const childId =
      $("#wc-category-child").length && $("#wc-category-child").val()
        ? $("#wc-category-child").val()
        : "";

    if (!categoryId) {
      addLogEntry(
        "Error: Debe ingresar un ID de categoría para BestStock",
        "error",
      );
      return;
    }

    if (!parentId) {
      addLogEntry(
        "Error: Debe seleccionar una categoría de WooCommerce",
        "error",
      );
      return;
    }

    const btn = $(this);
    btn.prop("disabled", true).text("Sincronizando...");

    // Inicialización de sincronización
    let syncInProgress = true;
    let syncPaused = false;
    let currentOffset = 0;
    let retryCount = 0;
    const maxRetries = 3;
    const batchSize = 1; // Ajusta según limitación de API
    let totalProcessed = 0;
    let totalProducts = 0;
    const syncStartTime = Date.now();

    $("#api-sync-status").html(`
    <div class="marpico-progress-container">
      <div class="marpico-progress-bar">
        <div class="marpico-progress-fill" style="width: 0%"></div>
      </div>
      <div class="marpico-progress-text">Iniciando sincronización...</div>
      <div class="sync-controls">
        <button id="pause-sync" class="marpico-btn">Pausar Sincronización</button>
        <button id="cancel-sync" class="marpico-btn" style="background: #ef4444; color: white;">Cancelar Sincronización</button>
      </div>
    </div>
  `);

    $("#pause-sync").on("click", () => {
      if (syncPaused) {
        syncPaused = false;
        $("#pause-sync").text("Pausar Sincronización");
        addLogEntry("Sincronización reanudada", "info");
        processBatch();
      } else {
        syncPaused = true;
        $("#pause-sync").text("Reanudar Sincronización");
        addLogEntry("Sincronización pausada", "warning");
      }
    });

    $("#cancel-sync").on("click", () => {
      syncInProgress = false;
      const elapsed = formatElapsedTime(syncStartTime);
      addLogEntry(
        `Sincronización cancelada en ${elapsed} (offset ${currentOffset})`,
        "warning",
      );
      btn.prop("disabled", false).text("Sincronizar Categoría");
      $("#api-sync-status").html("");
    });

    function processBatch() {
      if (!syncInProgress || syncPaused) return;

      $.ajax({
        url: marpico_ajax.ajax_url,
        type: "POST",
        timeout: 60000, // 60s
        data: {
          action: "beststock_sync_batch",
          security: marpico_ajax.nonce,
          category_id: categoryId,
          offset: currentOffset,
          batch_size: batchSize,
          wc_category_parent: parentId,
          wc_category_child: childId,
        },
        success(resp) {
          retryCount = 0;

          if (!resp.success) {
            return handleBatchError(resp.data || "Error desconocido");
          }

          const data = resp.data || {};
          totalProcessed += data.processed;
          totalProducts = data.total;

          const percentage =
            totalProducts > 0
              ? Math.round((totalProcessed / totalProducts) * 100)
              : 0;

          $(".marpico-progress-fill").css("width", percentage + "%");
          $(".marpico-progress-text").text(
            `${percentage}% - Procesados ${totalProcessed} de ${totalProducts} productos`,
          );

          addLogEntry(
            `Lote procesado: ${data.processed} productos (offset ${currentOffset})`,
            "info",
          );

          currentOffset += batchSize;

          if (currentOffset < totalProducts) {
            setTimeout(() => processBatch(), 500); // pequeña pausa
          } else {
            syncInProgress = false;
            const elapsed = formatElapsedTime(syncStartTime);
            const message = `Categoría ${categoryId} sincronizada exitosamente en ${elapsed}`;
            addLogEntry(message, "success");
            $("#api-sync-status").html(
              `<span class="beststock-log-success">✓ ${message}</span>`,
            );
            btn.prop("disabled", false).text("Sincronizar Categoría");
          }
        },
        error(xhr) {
          let errorMsg = `Error de conexión (HTTP ${
            xhr.status || "desconocido"
          })`;
          handleBatchError(errorMsg);
        },
      });
    }

    function handleBatchError(errorMsg) {
      retryCount++;
      if (retryCount <= maxRetries) {
        const retryDelay = 5000 * Math.pow(2, retryCount - 1); // exponential backoff
        addLogEntry(
          `Error: ${errorMsg}. Reintentando lote ${retryCount}/${maxRetries} en ${
            retryDelay / 1000
          }s`,
          "error",
        );

        setTimeout(() => {
          processBatch();
        }, retryDelay);
      } else {
        syncInProgress = false;
        const elapsed = formatElapsedTime(syncStartTime);
        addLogEntry(
          `Error persistente: ${errorMsg}. Lote detenido tras ${retryCount} intentos. Procesados hasta offset ${currentOffset} en ${elapsed}`,
          "error",
        );
        btn.prop("disabled", false).text("Sincronizar Categoría");
      }
    }

    processBatch(); // iniciar primer lote
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

  $("#test-provider").on("click", function (e) {
    e.preventDefault();
    var btn = $(this);

    if (syncInProgress) {
      if (syncPaused) {
        syncPaused = false;
        btn.text("Pausar Sincronización");
        addLogEntry("Sincronización reanudada", "info");
        resumeSync();
      } else {
        syncPaused = true;
        btn.text("Reanudar Sincronización");
        addLogEntry("Sincronización pausada", "warning");
      }
      return;
    }

    syncStartTime = Date.now();
    syncInProgress = true;
    syncPaused = false;
    currentOffset = 0;
    retryCount = 0;

    var totalProcessed = 0;
    var totalProducts = 0;
    var batchSize = 10;

    btn.text("Pausar Sincronización").addClass("marpico-btn-secondary");
    addLogEntry(
      `Iniciando sincronización por lotes de ${batchSize} productos`,
      "info",
    );

    $("#cdo-sync-status-batch").html(`
      <div class="marpico-progress-container">
        <div class="marpico-progress-bar">
          <div class="marpico-progress-fill" style="width: 0%"></div>
        </div>
        <div class="marpico-progress-text">Iniciando sincronización...</div>
        <div class="sync-controls">
          <button id="cancel-sync" class="marpico-btn" style="background: #ef4444; color: white;">Cancelar Sincronización</button>
        </div>
      </div>
    `);

    $("#cancel-sync").on("click", () => {
      cancelSync();
    });

    function cancelSync() {
      syncInProgress = false;
      syncPaused = false;
      const elapsedTime = formatElapsedTime(syncStartTime);
      const message = `Sincronización cancelada. Procesados ${totalProcessed} productos en ${elapsedTime}`;

      $("#cdo-sync-status-batch").html(
        `<span style="color: orange;">⚠ ${message}</span>`,
      );
      addLogEntry(message, "info");
      btn
        .prop("disabled", false)
        .text("Sincronizar Productos por Lotes")
        .removeClass("marpico-btn-secondary");
    }

    function resumeSync() {
      if (!syncPaused && syncInProgress) {
        syncBatch(currentOffset);
      }
    }

    function syncBatch(offset) {
      if (syncPaused || !syncInProgress) {
        return;
      }

      currentOffset = offset;
      batchStartTime = Date.now();

      console.log(
        `[v0] [${getCurrentTimestamp()}] Iniciando lote en offset: ${offset}`,
      );
      var currentPercentage =
        totalProducts > 0
          ? Math.round((totalProcessed / totalProducts) * 100)
          : 0;
      $(".marpico-progress-text").text(
        `${currentPercentage}% - Procesando lote desde posición ${offset}...`,
      );

      $.post(
        marpico_ajax.ajax_url,
        {
          action: "marpico_sync_batch",
          security: marpico_ajax.nonce,
          offset: offset,
          batch_size: batchSize,
        },
        (resp) => {
          const batchElapsed = formatElapsedTime(batchStartTime);
          console.log(
            `[v0] [${getCurrentTimestamp()}] Respuesta recibida en ${batchElapsed}:`,
            resp,
          );

          if (resp.success) {
            retryCount = 0;
            var data = resp.data;
            totalProcessed += data.result.processed;
            totalProducts = data.result.total;

            var percentage = Math.round((totalProcessed / totalProducts) * 100);

            $(".marpico-progress-fill")
              .css("width", percentage + "%")
              .get(0).offsetHeight;
            $(".marpico-progress-text")
              .text(
                `${percentage}% - Procesados ${totalProcessed} de ${totalProducts} productos`,
              )
              .get(0).offsetHeight;

            const logMessage = `Lote completado: ${resp.data.result.processed} productos en ${batchElapsed} (${percentage}% total)`;
            addLogEntry(logMessage, "success");
            console.log(`[v0] [${getCurrentTimestamp()}] ${logMessage}`);

            updateStats();

            if (data.result.has_more && syncInProgress && !syncPaused) {
              setTimeout(() => {
                syncBatch(data.result.next_offset);
              }, 200);
            } else if (!data.result.has_more) {
              syncInProgress = false;
              const totalElapsed = formatElapsedTime(syncStartTime);
              const completionMessage = `✓ Sincronización completada: ${totalProcessed} productos procesados en ${totalElapsed}`;

              console.log(
                `[v0] [${getCurrentTimestamp()}] ${completionMessage}`,
              );
              addLogEntry(completionMessage, "success");

              $("#cdo-sync-status-batch").html(
                `<div class="marpico-log-success" style="padding: 16px; text-align: center; font-weight: 600;">${completionMessage}</div>`,
              );
              btn
                .prop("disabled", false)
                .text("Sincronizar Productos por Lotes")
                .removeClass("marpico-btn-secondary");
              updateStats();
            }
          } else {
            const errorMessage = `Error en lote (offset ${offset}): ${
              resp.data || "Error desconocido"
            }`;
            console.log(`[v0] [${getCurrentTimestamp()}] ${errorMessage}`);
            addLogEntry(errorMessage, "error");
            handleSyncError(resp.data || "Error desconocido", offset);
          }
        },
      ).fail((xhr) => {
        const batchElapsed = formatElapsedTime(batchStartTime);
        let errorMsg = "Error de conexión";
        if (xhr.status === 0) {
          errorMsg = "Sin conexión a internet o servidor no disponible";
        } else if (xhr.status === 500) {
          errorMsg = "Error interno del servidor";
        } else if (xhr.status === 504) {
          errorMsg = "Timeout del servidor";
        }

        const fullErrorMsg = `${errorMsg} después de ${batchElapsed} (offset ${offset})`;
        console.log(
          `[v0] [${getCurrentTimestamp()}] Error AJAX: ${xhr.status} ${
            xhr.statusText
          } - ${fullErrorMsg}`,
        );
        addLogEntry(fullErrorMsg, "error");
        handleSyncError(errorMsg, offset);
      });
    }

    function handleSyncError(errorMsg, offset) {
      retryCount++;

      if (retryCount <= maxRetries) {
        const retryMessage = `Reintentando lote ${retryCount}/${maxRetries} en 5 segundos (offset ${offset})`;
        addLogEntry(retryMessage, "info");

        $(".marpico-progress-text").html(`
          Error: ${errorMsg}<br>
          <div class="retry-info">${retryMessage}</div>
        `);

        setTimeout(() => {
          if (syncInProgress && !syncPaused) {
            syncBatch(offset);
          }
        }, 5000);
      } else {
        syncInProgress = false;
        const elapsedTime = formatElapsedTime(syncStartTime);
        const finalErrorMsg = `Error después de ${maxRetries} intentos: ${errorMsg}. Procesados ${totalProcessed} productos en ${elapsedTime}`;

        console.log(`[v0] [${getCurrentTimestamp()}] ${finalErrorMsg}`);
        addLogEntry(finalErrorMsg, "error");

        $("#cdo-sync-status-batch").html(`
          <span style="color: red;">❌ ${finalErrorMsg}</span><br>
          <button id="resume-from-error" class="marpico-btn" style="margin-top: 10px; background: #0073aa; color: white;">Continuar desde donde se quedó</button>
        `);

        $("#resume-from-error").on("click", function () {
          retryCount = 0;
          syncInProgress = true;
          syncPaused = false;
          syncStartTime = Date.now(); // Reiniciar tiempo
          btn.text("Pausar Sincronización").addClass("marpico-btn-secondary");
          addLogEntry("Reanudando sincronización desde error", "info");
          $(this)
            .parent()
            .html(
              '<div class="marpico-progress-text">Reanudando sincronización...</div>',
            );
          setTimeout(() => {
            syncBatch(currentOffset);
          }, 1000);
        });

        btn
          .prop("disabled", false)
          .text("Sincronizar Productos por Lotes")
          .removeClass("marpico-btn-secondary");
      }
    }
    syncBatch(0);
  });
});
