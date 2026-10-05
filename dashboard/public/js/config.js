/* Plugin Dashboard - página de configuração: abas, multiselect e confirmação de exclusão */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-dashboard-config]');
    if (!raiz) {
        return;
    }

    raiz.querySelectorAll('[data-aba]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            raiz.querySelectorAll('[data-aba]').forEach(function (x) { x.classList.toggle('active', x === a); });
            raiz.querySelectorAll('[data-aba-painel]').forEach(function (p) { p.hidden = p.dataset.abaPainel !== a.dataset.aba; });
            try {
                var url = new URL(window.location.href);
                url.searchParams.set('aba', a.dataset.aba);
                window.history.replaceState(null, '', url.toString());
            } catch (err) { /* navegador antigo */ }
        });
    });

    var opcoes = function (ms) {
        return Array.from(ms.querySelectorAll('.dashboard-ms-opcao'));
    };
    var atualizar = function (ms) {
        var lista = opcoes(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        ms.querySelector('.dashboard-ms-texto').textContent = !marcadas.length ? (ms.dataset.placeholder || 'Selecione...')
            : (marcadas.length <= 2 ? marcadas.map(function (o) { return o.querySelector('span').textContent; }).join(', ') : marcadas.length + ' selecionado(s)');
        ms.querySelector('.dashboard-ms-contador').textContent = marcadas.length + ' de ' + lista.length + ' selecionado(s)';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-dashboard-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };
    var reordenar = function (ms) {
        var caixa = ms.querySelector('.dashboard-ms-opcoes');
        opcoes(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.dataset.label.localeCompare(b.dataset.label, 'pt-BR', { numeric: true });
        }).forEach(function (o) { caixa.appendChild(o); });
    };
    var filtrar = function (ms) {
        var termo = ms.querySelector('.dashboard-ms-busca').value.trim().toLowerCase();
        opcoes(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        atualizar(ms);
    };

    raiz.querySelectorAll('[data-dashboard-ms]').forEach(function (ms) {
        // Campo oculto: lista vazia também é enviada
        var oculto = document.createElement('input');
        oculto.type = 'hidden';
        oculto.name = ms.dataset.name + '[]';
        oculto.value = '-1';
        ms.appendChild(oculto);
        var drop = ms.querySelector('.dashboard-ms-dropdown');
        var busca = ms.querySelector('.dashboard-ms-busca');
        ms.querySelector('[data-dashboard-ms-abrir]').addEventListener('click', function () {
            var abrir = drop.hidden;
            raiz.querySelectorAll('.dashboard-ms-dropdown').forEach(function (d) { d.hidden = true; });
            drop.hidden = !abrir;
            if (abrir) {
                busca.focus();
            }
        });
        busca.addEventListener('input', function () { filtrar(ms); });
        busca.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
        ms.querySelector('[data-dashboard-ms-todos]').addEventListener('change', function (e) {
            opcoes(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
            reordenar(ms);
            atualizar(ms);
        });
        opcoes(ms).forEach(function (o) {
            var c = o.querySelector('input');
            c.addEventListener('change', function () {
                o.classList.toggle('selected', c.checked);
                if (busca.value) {
                    busca.value = '';
                    filtrar(ms);
                }
                reordenar(ms);
                atualizar(ms);
            });
        });
        atualizar(ms);
    });

    document.addEventListener('click', function (e) {
        raiz.querySelectorAll('[data-dashboard-ms]').forEach(function (ms) {
            if (!ms.contains(e.target)) {
                ms.querySelector('.dashboard-ms-dropdown').hidden = true;
            }
        });
    });

    // Confirmação no próprio botão (sem confirm do navegador)
    raiz.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-dashboard-confirmar]');
        if (!btn || btn.dataset.confirmado === '1') {
            return;
        }
        e.preventDefault();
        btn.dataset.confirmado = '1';
        btn.classList.add('dashboard-confirmando');
        btn.innerHTML = '<i class="ti ti-check"></i> Confirmar';
        btn.title = btn.dataset.dashboardConfirmar;
        setTimeout(function () {
            btn.dataset.confirmado = '';
            btn.classList.remove('dashboard-confirmando');
            btn.innerHTML = '<i class="ti ti-trash"></i>';
        }, 4000);
    });
})();
