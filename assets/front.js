(function () {
    "use strict";

    var HTMD = window.HTMD || {};
    var activeTab = "files"; // "files" | "folder" | "archive"
    var droppedFolderFiles = null; // File[] collected from a dragged directory

    /* ─── Tiny helpers ────────────────────────────────────── */
    function esc(str) {
        var d = document.createElement("div");
        d.textContent = String(str);
        return d.innerHTML;
    }

    function pad2(n) { return n < 10 ? "0" + n : String(n); }

    function nowFormatted() {
        var d = new Date();
        return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()) +
               " " + pad2(d.getHours()) + ":" + pad2(d.getMinutes()) + ":" + pad2(d.getSeconds());
    }

    function i18n(key, fallback) {
        return (HTMD.i18n && HTMD.i18n[key]) ? HTMD.i18n[key] : fallback;
    }

    function el(id) { return document.getElementById(id); }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + " KB";
        return (bytes / 1048576).toFixed(1) + " MB";
    }

    function fatalError(msg) {
        var wrap = el("htmd-app") || document.body;
        var banner = document.createElement("div");
        banner.style.cssText = "margin:16px 0;padding:12px 16px;background:#141414;" +
                               "border:2px solid oklch(0.52 0.22 25);" +
                               "color:oklch(0.52 0.22 25);font-size:14px;font-family:inherit";
        banner.innerHTML = "<strong>Hata</strong> — " + esc(msg);
        wrap.insertBefore(banner, wrap.firstChild);
        var prog = el("htmd-progress");
        if (prog) prog.setAttribute("hidden", "");
    }

    /* ─── Active input ────────────────────────────────────── */
    function getActiveInput() {
        if (activeTab === "files")   return el("htmd_files");
        if (activeTab === "folder")  return el("htmd_folder");
        if (activeTab === "archive") return el("htmd_archive");
        return null;
    }

    /* ─── Recursive directory reader (for drag-drop folder) ─ */
    function readDirEntry(entry, collected, done) {
        if (entry.isFile) {
            entry.file(function (f) {
                var ext = f.name.split(".").pop().toLowerCase();
                if (ext === "html" || ext === "htm") collected.push(f);
                done();
            }, done);
        } else if (entry.isDirectory) {
            var reader = entry.createReader();
            var readBatch = function () {
                reader.readEntries(function (entries) {
                    if (!entries.length) { done(); return; }
                    var remaining = entries.length;
                    entries.forEach(function (e) {
                        readDirEntry(e, collected, function () {
                            if (--remaining === 0) readBatch();
                        });
                    });
                }, done);
            };
            readBatch();
        } else {
            done();
        }
    }

    /* ─── File table ──────────────────────────────────────── */
    function renderFileTable(input) {
        var files = Array.isArray(input)
            ? input
            : (input && input.files) ? Array.from(input.files) : [];
        var tableWrap  = el("htmd-file-table");
        var tableRows  = el("htmd-file-table-rows");
        var tableLabel = el("htmd-file-table-label");
        var tableSize  = el("htmd-file-table-size");
        var dropzone   = el("htmd-dropzone");

        if (!files.length) {
            if (tableWrap) tableWrap.setAttribute("hidden", "");
            if (dropzone)  dropzone.classList.remove("has-files");
            return;
        }

        var totalBytes = files.reduce(function (acc, f) { return acc + f.size; }, 0);
        var maxMb = HTMD.maxUploadMb || "100";

        if (tableLabel) tableLabel.textContent = "SEÇİLEN — " + files.length + " DOSYA";
        if (tableSize)  tableSize.textContent  = formatSize(totalBytes) + " / " + maxMb + " MB";

        var display   = files.slice(0, 20);
        var moreCount = files.length - display.length;

        if (tableRows) {
            tableRows.innerHTML = display.map(function (f) {
                var ext = f.name.split(".").pop().toUpperCase().slice(0, 3);
                return '<div class="htmd-file-row">' +
                    '<div class="htmd-file-row__icon">' + esc(ext) + '</div>' +
                    '<div class="htmd-file-row__info">' +
                        '<div class="htmd-file-row__name">' + esc(f.name) + '</div>' +
                        '<div class="htmd-file-row__size">' + esc(formatSize(f.size)) + '</div>' +
                    '</div>' +
                    '<div class="htmd-file-row__status">BEKLİYOR</div>' +
                '</div>';
            }).join("") + (moreCount > 0
                ? '<div class="htmd-file-row htmd-file-row--more">+ ' + moreCount + ' dosya daha</div>'
                : '');
        }

        if (tableWrap) tableWrap.removeAttribute("hidden");
        if (dropzone)  dropzone.classList.add("has-files");
    }

    function clearFileTable() {
        droppedFolderFiles = null;
        renderFileTable(null);
    }

    /* ─── Tab switching ───────────────────────────────────── */
    function initTabs() {
        var tabs = document.querySelectorAll(".htmd-tab");
        var hints = {
            files:   "veya tıklayarak seçin · HTML ve HTM dosyaları",
            folder:  "tıklayarak klasör seçin · İçindeki HTML dosyaları işlenir",
            archive: "veya tıklayarak seçin · ZIP ve RAR arşivleri"
        };
        var pickLabels = {
            files:   "DOSYA SEÇ",
            folder:  "KLASÖR SEÇ",
            archive: "ARŞİV SEÇ"
        };

        tabs.forEach(function (btn) {
            btn.addEventListener("click", function () {
                var tab = btn.getAttribute("data-tab");
                if (tab === activeTab) return;
                activeTab = tab;

                tabs.forEach(function (t) {
                    var on = (t === btn);
                    t.classList.toggle("is-active", on);
                    t.setAttribute("aria-selected", on ? "true" : "false");
                });

                var hintEl  = el("htmd-dropzone-hint");
                var pickBtn = el("htmd-pick-btn");
                if (hintEl  && hints[tab])      hintEl.textContent  = hints[tab];
                if (pickBtn && pickLabels[tab]) pickBtn.textContent = pickLabels[tab];

                /* Clear all inputs on tab switch — start fresh */
                ["htmd_files", "htmd_folder", "htmd_archive"].forEach(function (id) {
                    var inp = el(id);
                    if (inp) { try { inp.value = ""; } catch (e) {} }
                });
                clearFileTable();
            });
        });
    }

    /* ─── Dropzone ────────────────────────────────────────── */
    function initDropzone() {
        var dropzone = el("htmd-dropzone");
        var pickBtn  = el("htmd-pick-btn");
        if (!dropzone) return;
        var dc = 0;

        dropzone.addEventListener("click", function (e) {
            if (pickBtn && (e.target === pickBtn || pickBtn.contains(e.target))) return;
            var input = getActiveInput();
            if (input) input.click();
        });

        if (pickBtn) {
            pickBtn.addEventListener("click", function (e) {
                e.stopPropagation();
                var input = getActiveInput();
                if (input) input.click();
            });
        }

        dropzone.addEventListener("dragenter", function (e) {
            e.preventDefault(); dc++; dropzone.classList.add("is-dragover");
        });
        dropzone.addEventListener("dragover", function (e) { e.preventDefault(); });
        dropzone.addEventListener("dragleave", function (e) {
            e.preventDefault(); dc--;
            if (dc <= 0) { dc = 0; dropzone.classList.remove("is-dragover"); }
        });
        dropzone.addEventListener("dragend", function () {
            dc = 0; dropzone.classList.remove("is-dragover");
        });
        dropzone.addEventListener("drop", function (e) {
            e.preventDefault(); dc = 0; dropzone.classList.remove("is-dragover");

            if (activeTab === "folder") {
                /* Use File System API to read dropped directory */
                var items = e.dataTransfer && e.dataTransfer.items;
                if (!items || !items.length) return;
                var entry = items[0].webkitGetAsEntry && items[0].webkitGetAsEntry();
                if (!entry) return;
                if (!entry.isDirectory) {
                    showError("Lütfen bir klasör sürükleyin.");
                    return;
                }
                var collected = [];
                renderFileTable(null); /* clear while loading */
                readDirEntry(entry, collected, function () {
                    if (!collected.length) {
                        showError("Klasörde HTML/HTM dosyası bulunamadı.");
                        return;
                    }
                    droppedFolderFiles = collected;
                    renderFileTable(collected);
                });
                return;
            }

            var dropped = e.dataTransfer && e.dataTransfer.files;
            if (!dropped || !dropped.length) return;
            try {
                var dt = new DataTransfer();
                Array.from(dropped).forEach(function (f) { dt.items.add(f); });
                var input = getActiveInput();
                if (input) { input.files = dt.files; renderFileTable(input); }
            } catch (err) { /* DataTransfer unavailable */ }
        });

        /* File input change listeners */
        ["htmd_files", "htmd_folder", "htmd_archive"].forEach(function (id) {
            var inp = el(id);
            if (inp) {
                inp.addEventListener("change", function () { renderFileTable(inp); });
            }
        });
    }

    /* ─── Stepper state ───────────────────────────────────── */
    function setStep(stepName) {
        var order = ["upload", "convert", "download"];
        var nums  = ["01", "02", "03"];
        var idx   = order.indexOf(stepName);
        document.querySelectorAll(".htmd-step").forEach(function (step) {
            var s    = step.getAttribute("data-step");
            var sIdx = order.indexOf(s);
            var numEl = step.querySelector(".htmd-step__num");
            step.classList.remove("is-active", "is-done");
            if (s === stepName) {
                step.classList.add("is-active");
                if (numEl) numEl.textContent = nums[sIdx] || s;
            } else if (sIdx < idx) {
                step.classList.add("is-done");
                if (numEl) numEl.textContent = "✓";
            } else {
                if (numEl) numEl.textContent = nums[sIdx] || s;
            }
        });
    }

    /* ─── Progress bar ────────────────────────────────────── */
    function setProgress(pct, label) {
        var bar    = el("htmd-progress-bar");
        var wrap   = el("htmd-progress-bar-wrap");
        var lbl    = el("htmd-progress-label");
        var pctEl  = el("htmd-progress-pct");
        var cursor = el("htmd-progress-cursor");
        if (bar)    bar.style.width = pct + "%";
        if (wrap)   wrap.setAttribute("aria-valuenow", String(pct));
        if (lbl && label !== undefined) lbl.textContent = label;
        if (pctEl)  pctEl.innerHTML = Math.round(pct) + "<span>%</span>";
        if (cursor) cursor.style.left = Math.min(pct, 97) + "%";
    }

    function showProgress(label) {
        setStep("convert");
        var prog = el("htmd-progress");
        var res  = el("htmd-result");
        if (prog) prog.removeAttribute("hidden");
        if (res)  res.setAttribute("hidden", "");
        setProgress(0, label || i18n("uploading", "Yükleniyor…"));
    }

    function hideProgress() {
        var prog = el("htmd-progress");
        if (prog) prog.setAttribute("hidden", "");
    }

    /* ─── Error display ───────────────────────────────────── */
    function showError(message) {
        try {
            hideProgress();
            var formCard = el("htmd-form-card");
            if (formCard) {
                formCard.removeAttribute("hidden");
                var existing = formCard.querySelector(".htmd-notice");
                if (existing) existing.parentNode.removeChild(existing);
                var notice = document.createElement("div");
                notice.className = "htmd-notice is-error";
                notice.innerHTML = "<strong>" + esc(i18n("error", "Hata")) + "</strong> — " + esc(message);
                formCard.insertBefore(notice, formCard.firstChild);
            } else {
                fatalError(message);
            }
        } catch (err) {
            fatalError(message);
        }
        setSubmitBusy(false);
        setStep("upload");
    }

    /* ─── Result panel ────────────────────────────────────── */
    function statCard(val, label, cls) {
        return '<div class="htmd-stat ' + (cls || "") + '">' +
            '<div class="htmd-stat__num">' + esc(String(val)) + '</div>' +
            '<div class="htmd-stat__lbl">' + esc(label) + '</div>' +
        '</div>';
    }

    function showResult(data, downloadUrl) {
        try {
            hideProgress();
            setStep("download");

            var report      = (data && data.report)    || {};
            var total       = parseInt(report.total,   10) || 0;
            var success     = parseInt(report.success, 10) || 0;
            var failed      = parseInt(report.failed,  10) || 0;
            var expiresIn   = parseInt(data && data.expires_in, 10) || 600;
            var failedFiles = (report.failed_files && Array.isArray(report.failed_files))
                ? report.failed_files : [];

            var timeEl = el("htmd-result-time");
            if (timeEl) timeEl.textContent = nowFormatted();

            var statsEl = el("htmd-result-stats");
            if (statsEl) {
                statsEl.innerHTML =
                    statCard(success, i18n("successCount", "Başarılı"), "htmd-stat--ok") +
                    statCard(failed,  i18n("failedCount",  "Hatalı"),   failed > 0 ? "htmd-stat--fail" : "") +
                    statCard(total,   "Toplam", "");
            }

            var failedWrap        = el("htmd-result-failed");
            var failedList        = el("htmd-failed-list");
            var failedToggle      = el("htmd-failed-toggle");
            var failedToggleLabel = el("htmd-failed-toggle-label");

            if (failed > 0 && failedFiles.length > 0 && failedWrap) {
                failedWrap.removeAttribute("hidden");
                if (failedToggleLabel) {
                    failedToggleLabel.textContent =
                        i18n("failedListTitle", "Başarısız Dosyalar") + " (" + failed + ")";
                }
                if (failedList) {
                    failedList.innerHTML = failedFiles.map(function (f) {
                        return '<li>' +
                            '<span class="htmd-failed-name">' + esc(f.name || "") + '</span>' +
                            (f.error ? '<span class="htmd-failed-err">' + esc(f.error) + '</span>' : '') +
                        '</li>';
                    }).join("");
                }
                if (failedToggle) {
                    failedToggle.addEventListener("click", function () {
                        var expanded = failedToggle.getAttribute("aria-expanded") === "true";
                        failedToggle.setAttribute("aria-expanded", expanded ? "false" : "true");
                        if (failedList) {
                            if (expanded) failedList.setAttribute("hidden", "");
                            else          failedList.removeAttribute("hidden");
                        }
                    });
                }
            } else if (failedWrap) {
                failedWrap.setAttribute("hidden", "");
            }

            var actionsEl = el("htmd-result-actions");
            if (actionsEl) {
                actionsEl.innerHTML = "";

                if (success > 0 && downloadUrl) {
                    var dlBtn = document.createElement("a");
                    dlBtn.className = "htmd-btn htmd-btn--dl";
                    dlBtn.id = "htmd-dl-btn";
                    dlBtn.href = downloadUrl;
                    dlBtn.setAttribute("download", "");
                    dlBtn.innerHTML =
                        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
                        ' stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                        '<path d="M12 4v12M6 12l6 6 6-6M4 20h16"/></svg> ' +
                        esc("ZIP İNDİR (" + success + " DOSYA)");
                    actionsEl.appendChild(dlBtn);

                    var expiryEl = el("htmd-result-expiry");
                    var _seconds = expiresIn;
                    var _dlBtn   = dlBtn;
                    var tick = function () {
                        if (_seconds <= 0) {
                            if (expiryEl) expiryEl.textContent = i18n("linkExpired", "İndirme bağlantısının süresi doldu.");
                            _dlBtn.classList.add("is-expired");
                            return;
                        }
                        if (expiryEl) {
                            var m = Math.floor(_seconds / 60), s = _seconds % 60;
                            expiryEl.textContent = "⏱ " + (m > 0
                                ? m + ":" + pad2(s) + " " + i18n("expiresMin", "dakika içinde sona erer")
                                : s + " " + i18n("expiresSec", "saniye içinde sona erer"));
                        }
                        _seconds--;
                        setTimeout(tick, 1000);
                    };
                    tick();

                    try {
                        var ghost = document.createElement("a");
                        ghost.href = downloadUrl;
                        ghost.style.display = "none";
                        document.body.appendChild(ghost);
                        ghost.click();
                        document.body.removeChild(ghost);
                    } catch (dlErr) {}
                }

                var retryBtn = document.createElement("button");
                retryBtn.type = "button";
                retryBtn.className = "htmd-btn htmd-btn--secondary";
                retryBtn.textContent = i18n("retry", "Yeni İş Başlat");
                retryBtn.addEventListener("click", resetApp);
                actionsEl.appendChild(retryBtn);
            }

            var resultCard = el("htmd-result");
            if (resultCard) resultCard.removeAttribute("hidden");

        } catch (err) {
            fatalError("Sonuç paneli gösterilirken hata: " + (err && err.message ? err.message : String(err)));
        }
    }

    /* ─── Submit button state ─────────────────────────────── */
    function setSubmitBusy(busy) {
        var btn = el("htmd-submit-btn");
        if (!btn) return;
        btn.disabled = busy;
        btn.classList.toggle("is-busy", busy);
    }

    /* ─── Build ZIP from folder ───────────────────────────── */
    function buildFolderZip(source, onProgress) {
        return new Promise(function (resolve, reject) {
            if (typeof JSZip === "undefined") {
                reject(new Error("JSZip yüklenemedi. Lütfen sayfayı yenileyip tekrar deneyin."));
                return;
            }
            var allFiles  = Array.isArray(source) ? source
                          : (source && source.files ? Array.from(source.files) : []);
            var htmlFiles = allFiles.filter(function (f) {
                var ext = f.name.split(".").pop().toLowerCase();
                return ext === "html" || ext === "htm";
            });
            if (!htmlFiles.length) { resolve(null); return; }
            var zip  = new JSZip();
            var done = 0;
            var readers = htmlFiles.map(function (file) {
                return new Promise(function (res, rej) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        zip.file(file.webkitRelativePath || file.name, e.target.result);
                        done++;
                        if (onProgress) onProgress(Math.round((done / htmlFiles.length) * 40));
                        res();
                    };
                    reader.onerror = rej;
                    reader.readAsArrayBuffer(file);
                });
            });
            Promise.all(readers)
                .then(function () {
                    return zip.generateAsync(
                        { type: "blob", compression: "DEFLATE", compressionOptions: { level: 6 } },
                        function (meta) { if (onProgress) onProgress(40 + Math.round(meta.percent * 0.3)); }
                    );
                })
                .then(resolve)
                .catch(reject);
        });
    }

    /* ─── Form submit ─────────────────────────────────────── */
    function handleSubmit(e) {
        e.preventDefault();

        var form         = el("htmd-form");
        var filesInput   = el("htmd_files");
        var folderInput  = el("htmd_folder");
        var archiveInput = el("htmd_archive");

        var hasFiles   = filesInput   && filesInput.files   && filesInput.files.length   > 0;
        var hasFolder  = (droppedFolderFiles && droppedFolderFiles.length > 0) ||
                         (folderInput && folderInput.files && folderInput.files.length > 0);
        var hasArchive = archiveInput && archiveInput.files && archiveInput.files.length > 0;

        if (!hasFiles && !hasFolder && !hasArchive) {
            showError("Lütfen en az bir dosya, klasör veya arşiv seçin.");
            return;
        }

        setSubmitBusy(true);
        var formCard = el("htmd-form-card");
        if (formCard) formCard.setAttribute("hidden", "");

        var folderZipPromise;
        if (hasFolder) {
            showProgress(i18n("buildingZip", "Klasör paketleniyor…"));
            var folderSource = droppedFolderFiles || folderInput;
            folderZipPromise = buildFolderZip(folderSource, function (pct) {
                setProgress(pct, i18n("buildingZip", "Klasör paketleniyor…"));
            });
        } else {
            folderZipPromise = Promise.resolve(null);
        }

        folderZipPromise.then(function (folderZipBlob) {
            showProgress(i18n("uploading", "Yükleniyor…"));

            var nonceInput = form && form.querySelector('[name="htmd_nonce"]');
            if (!nonceInput) {
                showError("Güvenlik jetonu (nonce) bulunamadı. Lütfen sayfayı yenileyip tekrar deneyin.");
                return;
            }

            var formData = new FormData();
            formData.append("action",     "htmd_submit_job");
            formData.append("htmd_nonce", nonceInput.value);

            var diacritics = form.querySelector('[name="htmd_remove_arabic_diacritics"]');
            if (diacritics && diacritics.checked) formData.append("htmd_remove_arabic_diacritics", "1");
            var footnotes = form.querySelector('[name="htmd_remove_footnotes"]');
            if (footnotes && footnotes.checked) formData.append("htmd_remove_footnotes", "1");

            if (hasFiles) {
                Array.from(filesInput.files).forEach(function (f) { formData.append("htmd_files[]", f); });
            }
            if (hasArchive) {
                formData.append("htmd_archive", archiveInput.files[0]);
            } else if (folderZipBlob) {
                var folderName = "folder";
                if (droppedFolderFiles && droppedFolderFiles.length) {
                    var rp = droppedFolderFiles[0].webkitRelativePath;
                    if (rp) folderName = rp.split("/")[0] || "folder";
                } else if (folderInput && folderInput.files && folderInput.files[0]) {
                    var rp2 = folderInput.files[0].webkitRelativePath;
                    if (rp2) folderName = rp2.split("/")[0] || "folder";
                }
                formData.append("htmd_archive", folderZipBlob, folderName + ".zip");
            }

            var xhr = new XMLHttpRequest();
            xhr.open("POST", HTMD.ajaxUrl || "/wp-admin/admin-ajax.php", true);

            xhr.upload.addEventListener("progress", function (ev) {
                if (ev.lengthComputable) {
                    setProgress(Math.round((ev.loaded / ev.total) * 70) + 10,
                                i18n("uploading", "Yükleniyor…"));
                }
            });
            xhr.upload.addEventListener("load", function () {
                setProgress(85, i18n("processing", "Dönüştürülüyor…"));
            });

            xhr.addEventListener("load", function () {
                try {
                    setProgress(100, i18n("done", "Tamamlandı"));

                    if (xhr.status < 200 || xhr.status >= 300) {
                        showError("Sunucu " + xhr.status + " hatası döndürdü. Lütfen tekrar deneyin.");
                        return;
                    }

                    var raw = xhr.responseText || "";
                    var response;
                    try {
                        response = JSON.parse(raw);
                    } catch (parseErr) {
                        var preview = raw.length > 200 ? raw.substring(0, 200) + "…" : raw;
                        showError("Sunucudan geçersiz yanıt alındı. İlk 200 karakter: " + preview);
                        return;
                    }

                    if (!response || !response.success) {
                        var msg = (response && response.data && response.data.message)
                            ? response.data.message
                            : "Bilinmeyen bir hata oluştu.";
                        showError(msg);
                        return;
                    }

                    showResult(response.data, response.data.download_url);

                } catch (loadErr) {
                    fatalError("Yanıt işlenirken beklenmeyen hata: " +
                               (loadErr && loadErr.message ? loadErr.message : String(loadErr)));
                }
            });

            xhr.addEventListener("error",   function () { showError("Ağ hatası. Lütfen bağlantınızı kontrol edip tekrar deneyin."); });
            xhr.addEventListener("timeout", function () { showError("İstek zaman aşımına uğradı. Daha az dosya ile tekrar deneyin."); });
            xhr.timeout = 300000;
            xhr.send(formData);

        }).catch(function (err) {
            showError((err && err.message) ? err.message : "Klasör ZIP oluşturulurken hata oluştu.");
        });
    }

    /* ─── Reset ───────────────────────────────────────────── */
    function resetApp() {
        var res  = el("htmd-result");
        var prog = el("htmd-progress");
        var card = el("htmd-form-card");
        if (res)  res.setAttribute("hidden", "");
        if (prog) prog.setAttribute("hidden", "");
        if (card) card.removeAttribute("hidden");

        var notice = card && card.querySelector(".htmd-notice");
        if (notice) notice.parentNode.removeChild(notice);

        var form = el("htmd-form");
        if (form) form.reset();

        clearFileTable();

        activeTab = "files";
        document.querySelectorAll(".htmd-tab").forEach(function (tab) {
            var isFiles = tab.getAttribute("data-tab") === "files";
            tab.classList.toggle("is-active", isFiles);
            tab.setAttribute("aria-selected", isFiles ? "true" : "false");
        });
        var hintEl = el("htmd-dropzone-hint");
        if (hintEl) hintEl.textContent = "veya tıklayarak seçin · HTML ve HTM dosyaları";
        var pickBtn = el("htmd-pick-btn");
        if (pickBtn) pickBtn.textContent = "DOSYA SEÇ";

        setStep("upload");
        setSubmitBusy(false);
    }

    /* ─── Init ────────────────────────────────────────────── */
    document.addEventListener("DOMContentLoaded", function () {
        initTabs();
        initDropzone();
        var form = el("htmd-form");
        if (form) form.addEventListener("submit", handleSubmit);
    });

}());
