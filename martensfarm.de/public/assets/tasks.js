// Aufgaben-Board: Karten per Drag & Drop zwischen den Spalten verschieben.
// Ohne JavaScript gehen die Pfeil-Knöpfe auf den Karten. CSP-konform: kein Inline-Code.
(function () {
    'use strict';

    function init() {
        var board = document.querySelector('.board');
        if (!board) {
            return;
        }
        var moveUrl = board.getAttribute('data-move-url');
        var csrf = board.getAttribute('data-csrf');
        var dragged = null;

        function columns() {
            return board.querySelectorAll('.board-col');
        }

        function clear() {
            columns().forEach(function (c) {
                c.classList.remove('drop-ok', 'drop-no', 'drop-hover');
            });
        }

        board.addEventListener('dragstart', function (ev) {
            var card = ev.target.closest ? ev.target.closest('.task-card[draggable="true"]') : null;
            if (!card) {
                return;
            }
            dragged = card;
            card.classList.add('dragging');
            ev.dataTransfer.effectAllowed = 'move';
            ev.dataTransfer.setData('text/plain', card.getAttribute('data-id'));
            var allowed = (card.getAttribute('data-moves') || '').split(',');
            columns().forEach(function (c) {
                var st = c.getAttribute('data-status');
                if (c.contains(card)) {
                    return;
                }
                c.classList.add(allowed.indexOf(st) >= 0 ? 'drop-ok' : 'drop-no');
            });
        });

        board.addEventListener('dragend', function () {
            if (dragged) {
                dragged.classList.remove('dragging');
            }
            dragged = null;
            clear();
        });

        board.addEventListener('dragover', function (ev) {
            var col = ev.target.closest ? ev.target.closest('.board-col.drop-ok') : null;
            if (!col || !dragged) {
                return;
            }
            ev.preventDefault();
            ev.dataTransfer.dropEffect = 'move';
            columns().forEach(function (c) { c.classList.toggle('drop-hover', c === col); });
        });

        board.addEventListener('drop', function (ev) {
            var col = ev.target.closest ? ev.target.closest('.board-col.drop-ok') : null;
            if (!col || !dragged) {
                return;
            }
            ev.preventDefault();
            var card = dragged;
            var body = new FormData();
            body.append('_csrf', csrf);
            body.append('id', card.getAttribute('data-id'));
            body.append('status', col.getAttribute('data-status'));
            col.querySelector('.board-cards').prepend(card);
            card.classList.add('saving');
            clear();
            fetch(moveUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unerwartete Antwort vom Server.' }; }); })
                .then(function (res) {
                    if (!res.ok && res.message) {
                        try { sessionStorage.setItem('mf_task_error', res.message); } catch (e) { /* egal */ }
                    }
                    window.location.reload();   // Zuweisung, Zähler und Knöpfe kommen frisch vom Server
                })
                .catch(function () { window.location.reload(); });
        });

        // Fehlermeldung nach dem Neuladen anzeigen (Erfolg kommt als Flash vom Server)
        try {
            var msg = sessionStorage.getItem('mf_task_error');
            if (msg) {
                sessionStorage.removeItem('mf_task_error');
                var div = document.createElement('div');
                div.className = 'alert alert-error';
                div.textContent = msg;
                var main = document.querySelector('.main');
                if (main) {
                    main.insertBefore(div, main.firstChild);
                }
            }
        } catch (e) { /* Speicher gesperrt: dann eben ohne Meldung */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
