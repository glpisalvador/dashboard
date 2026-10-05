/* Plugin Dashboard - painel: filtros em tempo real, widgets (ECharts do GLPI), detalhamento,
 * ampliação, exportações (PDF/PNG/CSV), e-mail, agendamentos e lista de painéis.
 * Utilitários e estado ficam em window.dashboard (o editor de layout usa os mesmos). */
(function () {
    'use strict';

    var baseEl = document.querySelector('script[data-dashboard-base]');
    if (!baseEl || window.dashboard) {
        return;
    }
    var base = JSON.parse(baseEl.textContent);
    var D = window.dashboard = { base: base, token: base.token || '', graficos: {}, dados: {}, editando: false };

    // Paleta suave e transparente (tons do GLPI)
    D.cores = ['rgba(70, 120, 180, 0.72)', 'rgba(229, 165, 75, 0.78)', 'rgba(25, 135, 84, 0.62)', 'rgba(111, 66, 193, 0.55)',
        'rgba(13, 202, 240, 0.6)', 'rgba(220, 53, 69, 0.55)', 'rgba(32, 201, 151, 0.55)', 'rgba(108, 117, 125, 0.55)',
        'rgba(253, 126, 20, 0.55)', 'rgba(102, 16, 242, 0.45)'];
    D.corComparacao = 'rgba(108, 117, 125, 0.35)';

    // ------------------------------------------------------------------ utilitários

    D.esc = function (t) {
        return String(t === null || t === undefined ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };

    D.lerJson = function (texto) {
        try {
            return JSON.parse(texto);
        } catch (e) {
            var m = String(texto).match(/\{[\s\S]*\}\s*$/);
            if (m) {
                try {
                    return JSON.parse(m[0]);
                } catch (e2) { /* segue */ }
            }
        }
        return { success: false, mensagem: 'Resposta inválida do servidor.' };
    };

    D.aviso = function (mensagem, erro) {
        var fn = erro ? window.glpi_toast_error : window.glpi_toast_info;
        if (typeof fn === 'function') {
            fn(mensagem);
        }
    };

    D.get = function (acao, params) {
        var p = new URLSearchParams();
        p.set('action', acao);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v !== null && v !== undefined) {
                p.set(k, typeof v === 'object' ? JSON.stringify(v) : v);
            }
        });
        return fetch(base.ajax + '?' + p.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(D.lerJson)
            .catch(function () { return { success: false, mensagem: 'Falha de comunicação com o servidor.' }; });
    };

    D.post = function (acao, dados) {
        var fd = dados instanceof FormData ? dados : new FormData();
        if (!(dados instanceof FormData)) {
            Object.keys(dados || {}).forEach(function (k) {
                var v = dados[k];
                if (Array.isArray(v)) {
                    v.forEach(function (x) { fd.append(k + '[]', x); });
                } else if (v !== null && v !== undefined) {
                    fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
                }
            });
        }
        fd.set('action', acao);
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        if (D.token) {
            fd.set('_glpi_csrf_token', D.token);
            cab['X-Glpi-Csrf-Token'] = D.token;
        }
        return fetch(base.ajax, { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var r = D.lerJson(t);
                if (r.new_token) {
                    D.token = r.new_token;
                }
                return r;
            })
            .catch(function () { return { success: false, mensagem: 'Falha de comunicação com o servidor.' }; });
    };

    /** Modal Bootstrap no visual do GLPI: cfg.titulo, icone, corpo, tamanho, botoes [{rotulo, icone, classe, acao(m)}] */
    D.modal = function (cfg) {
        var el = document.createElement('div');
        el.className = 'modal fade dashboard-modal';
        el.tabIndex = -1;
        el.innerHTML = '<div class="modal-dialog modal-dialog-scrollable' + (cfg.tamanho ? ' modal-' + cfg.tamanho : '') + '"><div class="modal-content">'
            + '<div class="modal-header"><h5 class="modal-title">' + (cfg.icone ? '<i class="' + D.esc(cfg.icone) + '"></i>' : '') + '<span>' + D.esc(cfg.titulo || '') + '</span></h5>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>'
            + '<div class="modal-body"></div><div class="modal-footer"></div></div></div>';
        document.body.appendChild(el);
        var m = { el: el, corpo: el.querySelector('.modal-body') };
        m.corpo.innerHTML = cfg.corpo || '';
        var rodape = el.querySelector('.modal-footer');
        var fechar = document.createElement('button');
        fechar.type = 'button';
        fechar.className = 'btn btn-sm dashboard-btn-cancelar';
        fechar.setAttribute('data-bs-dismiss', 'modal');
        fechar.innerHTML = '<i class="ti ti-x"></i><span>' + ((cfg.botoes || []).length ? 'Cancelar' : 'Fechar') + '</span>';
        rodape.appendChild(fechar);
        (cfg.botoes || []).forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm ' + (b.classe || 'dashboard-btn-principal');
            btn.innerHTML = (b.icone ? '<i class="' + D.esc(b.icone) + '"></i>' : '') + '<span>' + D.esc(b.rotulo) + '</span>';
            btn.addEventListener('click', function () {
                if (!btn.disabled) {
                    D.salvarEditores(el);
                    b.acao(m, btn);
                }
            });
            rodape.appendChild(btn);
        });
        var bs = new window.bootstrap.Modal(el);
        m.mostrar = function () { bs.show(); return m; };
        m.fechar = function () { bs.hide(); };
        el.addEventListener('hidden.bs.modal', function () {
            D.removerEditores(el);
            el.querySelectorAll('[data-dashboard-grafico-modal]').forEach(function (g) {
                var inst = window.echarts && window.echarts.getInstanceByDom(g);
                if (inst) {
                    inst.dispose();
                }
            });
            bs.dispose();
            el.remove();
            if (typeof cfg.aoFechar === 'function') {
                cfg.aoFechar();
            }
        });
        el.addEventListener('shown.bs.modal', function () {
            if (typeof cfg.aoMostrar === 'function') {
                cfg.aoMostrar(m);
            }
        });
        return m;
    };

    D.confirmar = function (titulo, texto, rotulo, acao) {
        D.modal({ titulo: titulo, icone: 'ti ti-alert-triangle', corpo: '<p class="mb-0">' + texto + '</p>',
            botoes: [{ rotulo: rotulo, icone: 'ti ti-check', classe: 'btn-danger', acao: acao }] }).mostrar();
    };

    D.editor = function (textarea, altura) {
        var cfg = window.tinymce_editor_configs && window.tinymce_editor_configs.dashboard_modelo;
        if (!window.tinymce || !cfg) {
            return;
        }
        if (!textarea.id) {
            textarea.id = 'dashboard_editor_' + Date.now();
        }
        window.tinymce.init(Object.assign({}, cfg, { selector: '#' + textarea.id, height: altura || 180, min_height: altura || 180 }));
    };

    D.valorEditor = function (textarea) {
        var ed = window.tinymce && textarea.id ? window.tinymce.get(textarea.id) : null;
        return ed ? ed.getContent() : textarea.value;
    };

    D.salvarEditores = function (raiz) {
        if (window.tinymce) {
            raiz.querySelectorAll('textarea[id]').forEach(function (t) {
                var ed = window.tinymce.get(t.id);
                if (ed) {
                    ed.save();
                }
            });
        }
    };

    D.removerEditores = function (raiz) {
        if (window.tinymce) {
            raiz.querySelectorAll('textarea[id]').forEach(function (t) {
                var ed = window.tinymce.get(t.id);
                if (ed) {
                    ed.remove();
                }
            });
        }
    };

    // ------------------------------------------------------------------ multiselect (filtros, e-mail, configuração)

    var opcoesMs = function (ms) {
        return Array.from(ms.querySelectorAll('.dashboard-ms-opcao'));
    };

    D.atualizarMs = function (ms) {
        var lista = opcoesMs(ms);
        var marcadas = lista.filter(function (o) { return o.querySelector('input').checked; });
        var texto = ms.querySelector('.dashboard-ms-texto');
        var nome = function (o) { return o.querySelector('span').textContent; };
        texto.textContent = !marcadas.length ? (ms.dataset.placeholder || 'Selecione...')
            : (marcadas.length <= 2 ? marcadas.map(nome).join(', ') : marcadas.length + ' selecionado(s)');
        ms.classList.toggle('dashboard-ms-ativo', marcadas.length > 0);
        ms.querySelector('.dashboard-ms-contador').textContent = marcadas.length ? marcadas.length + ' de ' + lista.length : '';
        var visiveis = lista.filter(function (o) { return !o.hidden; });
        var n = visiveis.filter(function (o) { return o.querySelector('input').checked; }).length;
        var todos = ms.querySelector('[data-dashboard-ms-todos]');
        todos.checked = visiveis.length > 0 && n === visiveis.length;
        todos.indeterminate = n > 0 && n < visiveis.length;
    };

    var reordenarMs = function (ms) {
        var caixa = ms.querySelector('.dashboard-ms-opcoes');
        opcoesMs(ms).sort(function (a, b) {
            var ca = a.querySelector('input').checked ? 0 : 1;
            var cb = b.querySelector('input').checked ? 0 : 1;
            return ca !== cb ? ca - cb : a.dataset.label.localeCompare(b.dataset.label, 'pt-BR', { numeric: true });
        }).forEach(function (o) { caixa.appendChild(o); });
    };

    var filtrarMs = function (ms) {
        var termo = ms.querySelector('.dashboard-ms-busca').value.trim().toLowerCase();
        opcoesMs(ms).forEach(function (o) { o.hidden = termo !== '' && o.dataset.label.indexOf(termo) < 0; });
        D.atualizarMs(ms);
    };

    D.valoresMs = function (ms) {
        return Array.from(ms.querySelectorAll('.dashboard-ms-opcao input:checked')).map(function (c) { return c.value; });
    };

    /** Multiselect montado no navegador (itens [{valor, rotulo}]) com o mesmo comportamento */
    D.criarMs = function (nome, itens, marcados, placeholder) {
        var h = '<div class="dashboard-ms" data-dashboard-ms data-name="' + D.esc(nome) + '" data-placeholder="' + D.esc(placeholder) + '">'
            + '<button type="button" class="dashboard-ms-cabecalho form-select form-select-sm" data-dashboard-ms-abrir><span class="dashboard-ms-texto"></span></button>'
            + '<div class="dashboard-ms-dropdown" hidden><div class="dashboard-ms-topo"><input type="text" class="form-control form-control-sm dashboard-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>'
            + '<label class="dashboard-ms-todos"><input type="checkbox" class="dashboard-check" data-dashboard-ms-todos> Marcar/desmarcar todos</label><div class="dashboard-ms-opcoes">';
        var sel = (marcados || []).map(String);
        itens.slice().sort(function (a, b) {
            var ma = sel.indexOf(String(a.valor)) >= 0 ? 0 : 1;
            var mb = sel.indexOf(String(b.valor)) >= 0 ? 0 : 1;
            return ma !== mb ? ma - mb : String(a.rotulo).localeCompare(String(b.rotulo), 'pt-BR');
        }).forEach(function (i) {
            var m = sel.indexOf(String(i.valor)) >= 0;
            h += '<label class="dashboard-ms-opcao' + (m ? ' selected' : '') + '" data-label="' + D.esc(String(i.rotulo).toLowerCase()) + '"><input type="checkbox" class="dashboard-check" value="' + D.esc(i.valor) + '"' + (m ? ' checked' : '') + '><span>' + D.esc(i.rotulo) + '</span></label>';
        });
        return h + '</div></div><div class="dashboard-ms-contador"></div></div>';
    };

    D.iniciarMs = function (raiz) {
        (raiz || document).querySelectorAll('[data-dashboard-ms]').forEach(D.atualizarMs);
    };

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('[data-dashboard-ms-abrir]');
        document.querySelectorAll('[data-dashboard-ms]').forEach(function (ms) {
            var drop = ms.querySelector('.dashboard-ms-dropdown');
            if (abrir && ms.contains(abrir)) {
                drop.hidden = !drop.hidden;
                if (!drop.hidden) {
                    ms.querySelector('.dashboard-ms-busca').focus();
                }
            } else if (!ms.contains(e.target)) {
                drop.hidden = true;
            }
        });
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.dashboard-ms-busca')) {
            filtrarMs(e.target.closest('[data-dashboard-ms]'));
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('.dashboard-ms-busca')) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        var ms = e.target.closest('[data-dashboard-ms]');
        if (!ms) {
            return;
        }
        if (e.target.matches('[data-dashboard-ms-todos]')) {
            opcoesMs(ms).forEach(function (o) {
                if (!o.hidden) {
                    o.querySelector('input').checked = e.target.checked;
                    o.classList.toggle('selected', e.target.checked);
                }
            });
        } else if (e.target.closest('.dashboard-ms-opcao')) {
            e.target.closest('.dashboard-ms-opcao').classList.toggle('selected', e.target.checked);
            var busca = ms.querySelector('.dashboard-ms-busca');
            if (busca.value) {
                busca.value = '';
                filtrarMs(ms);
            }
        } else {
            return;
        }
        reordenarMs(ms);
        D.atualizarMs(ms);
        ms.dispatchEvent(new CustomEvent('dashboard:ms', { bubbles: true }));
    });

    // ------------------------------------------------------------------ lista de painéis: novo painel

    D.novoPainel = function () {
        var cards = Object.keys(base.modelos).map(function (k, i) {
            var m = base.modelos[k];
            return '<label class="dashboard-modelo"><input type="radio" name="modelo" value="' + k + '"' + (i === 0 ? ' checked' : '') + '><span><strong>' + D.esc(m.rotulo) + '</strong><small>' + D.esc(m.descricao) + '</small></span></label>';
        }).join('');
        var vis = Object.keys(base.visibilidades).map(function (k) { return '<option value="' + k + '">' + D.esc(base.visibilidades[k]) + '</option>'; }).join('');
        var perfis = Object.keys(base.perfis).map(function (k) { return { valor: k, rotulo: base.perfis[k] }; });
        var m = D.modal({
            titulo: 'Novo painel', icone: 'ti ti-layout-dashboard', tamanho: 'lg',
            corpo: '<div class="dashboard-campo"><label>Nome <span class="text-danger">*</span></label><input type="text" class="form-control form-control-sm" data-nome maxlength="255" placeholder="Ex.: Service Desk - visão do gestor"></div>'
                + '<div class="dashboard-campo"><label>Começar com</label><div class="dashboard-modelos">' + cards + '</div></div>'
                + '<div class="dashboard-linha"><div class="dashboard-campo"><label>Quem pode ver</label><select class="form-select form-select-sm" data-vis>' + vis + '</select></div>'
                + '<div class="dashboard-campo" data-perfis hidden><label>Perfis</label>' + D.criarMs('perfis', perfis, [], 'Escolha os perfis') + '</div></div>',
            botoes: [{
                rotulo: 'Criar painel', icone: 'ti ti-check', acao: function (modal, btn) {
                    var c = modal.corpo;
                    var nome = c.querySelector('[data-nome]').value.trim();
                    if (!nome) {
                        D.aviso('Informe o nome do painel.', true);
                        return;
                    }
                    btn.disabled = true;
                    D.post('painel_criar', { name: nome, modelo: c.querySelector('input[name="modelo"]:checked').value, visibilidade: c.querySelector('[data-vis]').value,
                        perfis: D.valoresMs(c.querySelector('[data-dashboard-ms]')) }).then(function (r) {
                        if (r.success) {
                            window.location.href = r.url;
                        } else {
                            btn.disabled = false;
                            D.aviso(r.mensagem, true);
                        }
                    });
                },
            }],
            aoMostrar: function (modal) { modal.corpo.querySelector('[data-nome]').focus(); },
        });
        m.corpo.querySelector('[data-vis]').addEventListener('change', function (e) {
            m.corpo.querySelector('[data-perfis]').hidden = e.target.value !== 'perfis';
        });
        D.iniciarMs(m.corpo);
        m.mostrar();
    };

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-dashboard-novo]')) {
            e.preventDefault();
            D.novoPainel();
        }
    });

    var pagina = document.querySelector('[data-dashboard-pagina]');
    if (!pagina) {
        return;
    }

    // ==================================================================== painel
    var P = base.painel;
    var cat = base.catalogo;
    D.filtros = base.filtros;
    var grade = pagina.querySelector('[data-dashboard-grade]');
    var status = pagina.querySelector('[data-dashboard-status]');
    var timer = null;
    var pedido = 0;

    // ------------------------------------------------------------------ filtros

    var lerFiltros = function () {
        var f = pagina.querySelector('[data-dashboard-filtros]');
        var ativo = f.querySelector('[data-dashboard-periodo].ativo');
        var novo = {
            periodo: ativo ? ativo.dataset.dashboardPeriodo : '7d',
            comparar: f.querySelector('[data-dashboard-comparar]').value,
            situacao: f.querySelector('[data-dashboard-situacao]').value,
            modulos: Array.from(f.querySelectorAll('[data-dashboard-modulo]:checked')).map(function (c) { return c.value; }),
        };
        if (novo.periodo === 'personalizado') {
            novo.de = f.querySelector('[data-dashboard-de]').value;
            novo.ate = f.querySelector('[data-dashboard-ate]').value;
        }
        f.querySelectorAll('[data-dashboard-filtro]').forEach(function (ms) {
            novo[ms.dataset.dashboardFiltro] = D.valoresMs(ms).map(Number);
        });
        return novo;
    };

    var contarExtras = function () {
        var n = 0;
        pagina.querySelectorAll('[data-dashboard-filtro]').forEach(function (ms) {
            if (D.valoresMs(ms).length) {
                n++;
            }
        });
        var c = pagina.querySelector('[data-dashboard-contagem]');
        c.hidden = n === 0;
        c.textContent = n;
    };

    var aplicar = (function () {
        var t = null;
        return function (imediato) {
            clearTimeout(t);
            t = setTimeout(function () {
                D.filtros = lerFiltros();
                if (!D.filtros.modulos.length) {
                    D.aviso('Marque ao menos um módulo.', true);
                    return;
                }
                if (D.filtros.periodo === 'personalizado' && (!D.filtros.de || !D.filtros.ate)) {
                    return;
                }
                contarExtras();
                D.carregar();
            }, imediato ? 0 : 350);
        };
    })();

    var filtrosEl = pagina.querySelector('[data-dashboard-filtros]');
    filtrosEl.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-dashboard-periodo]');
        if (chip) {
            filtrosEl.querySelectorAll('[data-dashboard-periodo]').forEach(function (c) { c.classList.toggle('ativo', c === chip); });
            filtrosEl.querySelector('[data-dashboard-datas]').hidden = chip.dataset.dashboardPeriodo !== 'personalizado';
            if (chip.dataset.dashboardPeriodo === 'personalizado' && !filtrosEl.querySelector('[data-dashboard-de]').value) {
                var hoje = new Date();
                var ini = new Date(Date.now() - 29 * 86400000);
                var iso = function (d) { return d.toISOString().slice(0, 10); };
                filtrosEl.querySelector('[data-dashboard-de]').value = iso(ini);
                filtrosEl.querySelector('[data-dashboard-ate]').value = iso(hoje);
            }
            aplicar(true);
        }
        if (e.target.closest('[data-dashboard-mais-filtros]')) {
            var ex = filtrosEl.querySelector('[data-dashboard-extras]');
            ex.hidden = !ex.hidden;
        }
        if (e.target.closest('[data-dashboard-limpar]')) {
            D.restaurarFiltros(P.filtros);
            aplicar(true);
        }
    });
    filtrosEl.addEventListener('change', function (e) {
        if (e.target.matches('[data-dashboard-modulo], [data-dashboard-comparar], [data-dashboard-situacao], [data-dashboard-de], [data-dashboard-ate]')) {
            aplicar();
        }
    });
    filtrosEl.addEventListener('dashboard:ms', function () { aplicar(); });

    /** Coloca os filtros na tela (limpar = voltar aos padrões do painel) */
    D.restaurarFiltros = function (f) {
        filtrosEl.querySelectorAll('[data-dashboard-periodo]').forEach(function (c) { c.classList.toggle('ativo', c.dataset.dashboardPeriodo === (f.periodo || '7d')); });
        filtrosEl.querySelector('[data-dashboard-datas]').hidden = f.periodo !== 'personalizado';
        filtrosEl.querySelector('[data-dashboard-de]').value = f.de || '';
        filtrosEl.querySelector('[data-dashboard-ate]').value = f.ate || '';
        filtrosEl.querySelector('[data-dashboard-comparar]').value = f.comparar || 'nenhuma';
        filtrosEl.querySelector('[data-dashboard-situacao]').value = f.situacao || 'todos';
        filtrosEl.querySelectorAll('[data-dashboard-modulo]').forEach(function (c) { c.checked = (f.modulos || ['Ticket']).indexOf(c.value) >= 0; });
        filtrosEl.querySelectorAll('[data-dashboard-filtro]').forEach(function (ms) {
            var sel = (f[ms.dataset.dashboardFiltro] || []).map(String);
            ms.querySelectorAll('.dashboard-ms-opcao input').forEach(function (c) {
                c.checked = sel.indexOf(c.value) >= 0;
                c.closest('.dashboard-ms-opcao').classList.toggle('selected', c.checked);
            });
            reordenarMs(ms);
            D.atualizarMs(ms);
        });
    };

    // ------------------------------------------------------------------ grade de widgets

    var alturas = { p: 220, m: 320, g: 460 };

    D.renderGrade = function () {
        Object.keys(D.graficos).forEach(function (k) {
            if (D.graficos[k]) {
                D.graficos[k].dispose();
            }
        });
        D.graficos = {};
        grade.innerHTML = '';
        pagina.querySelector('[data-dashboard-sem-widgets]').hidden = P.widgets.length > 0;
        P.widgets.forEach(function (w) {
            var card = document.createElement('div');
            card.className = 'card dashboard-widget dashboard-w-' + w.tipo;
            card.style.gridColumn = 'span ' + w.largura;
            card.dataset.widget = w.id;
            var acoes = '';
            if (w.tipo === 'grafico' || w.tipo === 'calor') {
                acoes += '<button type="button" class="dashboard-icone" data-acao="ampliar" title="Ampliar com tabela"><i class="ti ti-arrows-maximize"></i></button>'
                    + '<button type="button" class="dashboard-icone" data-acao="png" title="Baixar imagem"><i class="ti ti-photo"></i></button>';
            }
            if (w.tipo !== 'indicador') {
                acoes += '<button type="button" class="dashboard-icone" data-acao="csv" title="Baixar CSV"><i class="ti ti-file-spreadsheet"></i></button>';
            }
            card.innerHTML = '<div class="dashboard-widget-topo" data-arrastar><span class="dashboard-widget-titulo">' + D.esc(w.titulo) + '</span>'
                + '<span class="dashboard-widget-acoes">' + acoes + '<span class="dashboard-edicao" data-edicao></span></span></div>'
                + '<div class="dashboard-widget-corpo" data-corpo' + (w.tipo !== 'indicador' && w.tipo !== 'lista' && w.tipo !== 'tecnicos' ? ' style="height:' + alturas[w.altura] + 'px"' : '') + '>'
                + '<div class="dashboard-carregando"><span class="dashboard-giro"></span></div></div>';
            grade.appendChild(card);
            if (D.dados[w.id]) {
                D.renderWidget(card, w, D.dados[w.id]);
            }
        });
        if (typeof D.aoRenderizar === 'function') {
            D.aoRenderizar();
        }
    };

    D.carregar = function () {
        var meu = ++pedido;
        status.innerHTML = '<span class="dashboard-giro"></span> Atualizando...';
        grade.classList.add('dashboard-atualizando');
        return D.get('dados', { painel: P.id, f: D.filtros }).then(function (r) {
            if (meu !== pedido) {
                return;
            }
            grade.classList.remove('dashboard-atualizando');
            if (!r.success) {
                status.textContent = r.mensagem || 'Falha ao carregar.';
                D.aviso(r.mensagem, true);
                return;
            }
            D.dados = r.widgets;
            D.periodoTexto = r.periodo;
            D.comparacaoTexto = r.comparacao;
            pagina.querySelector('[data-dashboard-periodo-texto]').textContent = r.periodo + (r.comparacao ? ' · comparado com ' + r.comparacao : '');
            status.innerHTML = '<i class="ti ti-circle-check"></i> Atualizado às ' + D.esc(r.hora) + (P.atualizacao ? ' · automático a cada ' + (P.atualizacao >= 60 ? (P.atualizacao / 60) + ' min' : P.atualizacao + ' s') : '');
            P.widgets.forEach(function (w) {
                var card = grade.querySelector('[data-widget="' + w.id + '"]');
                if (card && D.dados[w.id]) {
                    D.renderWidget(card, w, D.dados[w.id]);
                }
            });
            try {
                var url = new URL(window.location.href);
                url.searchParams.set('id', P.id);
                url.searchParams.set('f', JSON.stringify(D.filtros));
                window.history.replaceState(null, '', url.toString());
            } catch (e) { /* navegador antigo */ }
        });
    };

    // ------------------------------------------------------------------ desenho dos widgets

    var num = function (v) {
        return v === null || v === undefined ? '—' : Number(v).toLocaleString('pt-BR');
    };

    var sufixo = function (unidade) {
        return unidade === 'pct' ? '%' : (unidade === 'tempo' ? ' h' : '');
    };

    D.renderWidget = function (card, w, d) {
        var corpo = card.querySelector('[data-corpo]');
        var antigo = D.graficos[w.id];
        if (antigo) {
            antigo.dispose();
            delete D.graficos[w.id];
        }
        if (d.erro || d.aviso) {
            corpo.innerHTML = '<div class="dashboard-widget-aviso"><i class="ti ti-' + (d.erro ? 'alert-triangle' : 'info-circle') + '"></i><span>' + D.esc(d.erro || d.aviso) + '</span></div>';
            return;
        }
        ({ indicador: renderIndicador, grafico: renderGrafico, ranking: renderRanking, calor: renderCalor, tecnicos: renderTecnicos, lista: renderLista })[w.tipo](corpo, w, d);
    };

    function renderIndicador(corpo, w, d) {
        var variacao = '';
        if (d.variacao !== undefined && d.variacao !== null) {
            var sobe = d.variacao > 0;
            var bom = d.melhor === 'neutro' ? null : (d.melhor === 'alto' ? sobe : !sobe);
            variacao = '<span class="dashboard-variacao ' + (d.variacao === 0 || bom === null ? 'neutra' : (bom ? 'boa' : 'ruim')) + '" title="Antes: ' + D.esc(d.anterior_formatado) + '">'
                + '<i class="ti ti-trending-' + (d.variacao === 0 ? 'up' : (sobe ? 'up' : 'down')) + '"></i>' + (sobe ? '+' : '') + Number(d.variacao).toLocaleString('pt-BR') + '%</span>';
        } else if (d.anterior_formatado !== undefined) {
            variacao = '<span class="dashboard-variacao neutra" title="Período de comparação">antes: ' + D.esc(d.anterior_formatado) + '</span>';
        }
        corpo.innerHTML = '<div class="dashboard-kpi" data-detalhar="" title="' + D.esc(d.descricao) + ' Clique para ver os itens.">'
            + '<div class="dashboard-kpi-valor">' + D.esc(d.formatado) + '</div><div class="dashboard-kpi-rodape">' + variacao + '</div>'
            + (d.serie && d.serie.length > 1 ? '<div class="dashboard-kpi-serie" data-serie></div>' : '') + '</div>';
        var alvo = corpo.querySelector('[data-serie]');
        if (alvo && window.echarts) {
            var g = window.echarts.init(alvo, null, { renderer: 'svg' });
            g.setOption({
                animation: false, grid: { left: 0, right: 0, top: 2, bottom: 0 },
                xAxis: { type: 'category', show: false, data: d.serie.map(function (_, i) { return i; }) }, yAxis: { type: 'value', show: false },
                series: [{ type: 'line', data: d.serie, smooth: true, symbol: 'none', lineStyle: { width: 1.5, color: 'rgba(70,120,180,.7)' }, areaStyle: { color: 'rgba(70,120,180,.12)' } }],
            });
            D.graficos[corpo.closest('[data-widget]').dataset.widget] = g;
        }
    }

    /** Opções ECharts do gráfico (também usadas na ampliação) */
    D.opcaoGrafico = function (w, d, grande) {
        var tipo = w.grafico;
        var unidade = d.series[0].unidade;
        var circulo = tipo === 'pizza' || tipo === 'rosca';
        var tooltip = {
            trigger: circulo ? 'item' : 'axis', confine: true, axisPointer: { type: 'shadow' },
            formatter: function (p) {
                var lista = Array.isArray(p) ? p : [p];
                var titulo = circulo ? '' : '<strong>' + D.esc(lista[0].axisValueLabel || lista[0].name) + '</strong><br>';
                return titulo + lista.map(function (i) {
                    var s = d.series[i.seriesIndex];
                    var f = s ? s.formatados[i.dataIndex] : (i.value === null ? '—' : num(i.value) + sufixo(unidade));
                    return i.marker + D.esc(circulo ? i.name : i.seriesName) + ': <strong>' + D.esc(f) + '</strong>' + (circulo ? ' (' + i.percent + '%)' : '');
                }).join('<br>');
            },
        };
        if (circulo) {
            return {
                color: D.cores, tooltip: tooltip, legend: { type: 'scroll', bottom: 0, textStyle: { fontSize: 11, color: '#495057' }, icon: 'circle', itemWidth: 9, itemHeight: 9 },
                series: [{
                    type: 'pie', radius: tipo === 'rosca' ? ['45%', '72%'] : [0, '72%'], center: ['50%', '45%'], itemStyle: { borderColor: '#fff', borderWidth: 1 },
                    label: { show: !!grande, fontSize: 11, color: '#495057' }, labelLine: { show: !!grande },
                    data: d.categorias.map(function (c, i) { return { name: c, value: d.series[0].valores[i] }; }),
                }],
            };
        }
        var horizontal = tipo === 'barras_h';
        var categorias = { type: 'category', data: d.categorias, axisLabel: { fontSize: 10, color: '#6c757d', overflow: 'truncate', width: horizontal ? 140 : 90, hideOverlap: true }, axisTick: { show: false }, axisLine: { lineStyle: { color: '#dee2e6' } }, inverse: horizontal };
        var valores = { type: 'value', axisLabel: { fontSize: 10, color: '#6c757d', formatter: function (v) { return num(v) + sufixo(unidade); } }, splitLine: { lineStyle: { color: '#f0f0f0' } }, max: unidade === 'pct' ? 100 : null };
        var serieTipo = (tipo === 'linha' || tipo === 'area') ? 'line' : 'bar';
        var series = d.series.map(function (s, i) {
            return {
                name: s.nome, type: serieTipo, data: s.valores, smooth: serieTipo === 'line' ? 0.25 : false, symbolSize: 5, connectNulls: true, stack: tipo === 'empilhado' ? 'total' : null,
                barMaxWidth: 28, itemStyle: { color: D.cores[i % D.cores.length], borderRadius: serieTipo === 'bar' ? (horizontal ? [0, 3, 3, 0] : [3, 3, 0, 0]) : 0 },
                lineStyle: { width: 2 }, areaStyle: tipo === 'area' ? { opacity: 0.18 } : null,
            };
        });
        if (d.comparacao) {
            series.push({ name: 'Comparação', type: serieTipo, data: d.comparacao.valores, smooth: 0.25, barMaxWidth: 28, connectNulls: true,
                itemStyle: { color: D.corComparacao, borderRadius: serieTipo === 'bar' ? 3 : 0 }, lineStyle: { type: 'dashed', width: 1.5 }, symbol: 'none' });
        }
        return {
            color: D.cores, tooltip: tooltip,
            legend: series.length > 1 ? { top: 0, textStyle: { fontSize: 11, color: '#495057' }, icon: 'roundRect', itemWidth: 10, itemHeight: 8 } : { show: false },
            grid: { left: 8, right: 14, top: series.length > 1 ? 28 : 10, bottom: 6, containLabel: true },
            xAxis: horizontal ? valores : categorias, yAxis: horizontal ? categorias : valores,
            dataZoom: !horizontal && d.categorias.length > 40 ? [{ type: 'inside' }] : [],
            series: series,
        };
    };

    function renderGrafico(corpo, w, d) {
        if (!d.categorias.length) {
            corpo.innerHTML = '<div class="dashboard-widget-aviso"><i class="ti ti-mood-empty"></i><span>Sem dados no período.</span></div>';
            return;
        }
        corpo.innerHTML = '';
        if (!window.echarts) {
            corpo.innerHTML = '<div class="dashboard-widget-aviso">Biblioteca de gráficos indisponível.</div>';
            return;
        }
        var g = window.echarts.init(corpo, null, { renderer: 'canvas' });
        g.setOption(D.opcaoGrafico(w, d, false));
        g.on('click', function (p) {
            var comparacao = d.comparacao && p.seriesIndex === d.series.length;
            var s = d.series[comparacao ? 0 : p.seriesIndex] || d.series[0];
            D.detalhar(w, d.chaves[p.dataIndex], p.name, comparacao, s.metrica);
        });
        D.graficos[w.id] = g;
    }

    function renderRanking(corpo, w, d) {
        if (!d.itens.length) {
            corpo.innerHTML = '<div class="dashboard-widget-aviso"><i class="ti ti-mood-empty"></i><span>Sem dados no período.</span></div>';
            return;
        }
        corpo.innerHTML = '<ol class="dashboard-ranking">' + d.itens.map(function (i, n) {
            return '<li data-detalhar="' + D.esc(i.chave) + '" data-rotulo="' + D.esc(i.rotulo) + '"><span class="dashboard-pos">' + (n + 1) + '</span><span class="dashboard-ranking-nome"><span>' + D.esc(i.rotulo) + '</span>'
                + '<span class="dashboard-barra"><span style="width:' + Math.max(2, i.pct) + '%"></span></span></span><strong>' + D.esc(i.formatado) + '</strong></li>';
        }).join('') + '</ol>';
    }

    function renderCalor(corpo, w, d) {
        corpo.innerHTML = '';
        if (!window.echarts) {
            return;
        }
        var g = window.echarts.init(corpo);
        g.setOption(D.opcaoCalor(d));
        g.on('click', function (p) {
            var ordem = [2, 3, 4, 5, 6, 7, 1];
            D.detalhar(w, ordem[p.value[1]] + '-' + p.value[0], d.dias[p.value[1]] + ' ' + d.horas[p.value[0]], false, w.metricas[0]);
        });
        D.graficos[w.id] = g;
    }

    D.opcaoCalor = function (d) {
        return {
            tooltip: { position: 'top', formatter: function (p) { return D.esc(d.dias[p.value[1]] + ' ' + d.horas[p.value[0]]) + ': <strong>' + num(p.value[2]) + '</strong>'; } },
            grid: { left: 8, right: 8, top: 6, bottom: 40, containLabel: true },
            xAxis: { type: 'category', data: d.horas, splitArea: { show: true }, axisLabel: { fontSize: 10, color: '#6c757d' } },
            yAxis: { type: 'category', data: d.dias, splitArea: { show: true }, axisLabel: { fontSize: 10, color: '#6c757d' } },
            visualMap: { min: 0, max: Math.max(1, d.max), calculable: false, orient: 'horizontal', left: 'center', bottom: 0, itemHeight: 120, textStyle: { fontSize: 10, color: '#6c757d' },
                inRange: { color: ['rgba(70,120,180,0.05)', 'rgba(70,120,180,0.35)', 'rgba(229,165,75,0.75)'] } },
            series: [{ type: 'heatmap', data: d.valores, label: { show: false }, itemStyle: { borderColor: '#fff', borderWidth: 1 } }],
        };
    };

    function renderTecnicos(corpo, w, d) {
        if (!d.linhas.length) {
            corpo.innerHTML = '<div class="dashboard-widget-aviso"><i class="ti ti-mood-empty"></i><span>Nenhum técnico com itens no período.</span></div>';
            return;
        }
        var estado = corpo._ordem || { col: 'abertos', desc: true };
        var linhas = d.linhas.slice().sort(function (a, b) {
            var va = estado.col === 'nome' ? a.nome : ((a.valores[estado.col] || {}).v);
            var vb = estado.col === 'nome' ? b.nome : ((b.valores[estado.col] || {}).v);
            va = va === null || va === undefined ? -Infinity : va;
            vb = vb === null || vb === undefined ? -Infinity : vb;
            var r = typeof va === 'string' ? va.localeCompare(vb, 'pt-BR') : va - vb;
            return estado.desc ? -r : r;
        });
        var seta = function (c) { return estado.col === c ? ' <i class="ti ti-chevron-' + (estado.desc ? 'down' : 'up') + '"></i>' : ''; };
        var h = '<div class="dashboard-tabela-rolagem"><table class="table table-sm table-hover dashboard-tabela"><thead><tr><th data-ordenar="nome">Técnico' + seta('nome') + '</th>'
            + d.colunas.map(function (c) { return '<th class="text-end" data-ordenar="' + c.chave + '" title="' + (c.melhor === 'baixo' ? 'Menor é melhor' : (c.melhor === 'alto' ? 'Maior é melhor' : '')) + '">' + D.esc(c.rotulo) + seta(c.chave) + '</th>'; }).join('') + '</tr></thead><tbody>';
        linhas.forEach(function (l) {
            h += '<tr><td><a href="#" data-detalhar="' + l.id + '" data-rotulo="' + D.esc(l.nome) + '">' + D.esc(l.nome) + '</a></td>' + d.colunas.map(function (c) {
                var v = l.valores[c.chave] || { f: '—' };
                var destaque = v.rank === 1 && c.melhor !== 'neutro' && linhas.length > 1;
                return '<td class="text-end' + (destaque ? ' dashboard-melhor' : '') + '">' + D.esc(v.f) + (v.rank ? '<small class="dashboard-rank">' + v.rank + 'º</small>' : '') + '</td>';
            }).join('') + '</tr>';
        });
        corpo.innerHTML = h + '</tbody></table></div>';
        corpo.querySelectorAll('[data-ordenar]').forEach(function (th) {
            th.addEventListener('click', function () {
                var c = th.dataset.ordenar;
                corpo._ordem = { col: c, desc: estado.col === c ? !estado.desc : c !== 'nome' };
                renderTecnicos(corpo, w, d);
            });
        });
    }

    /** Tabela de itens (widget lista e detalhamento) */
    D.tabelaItens = function (d, opcoes) {
        var seta = function (c) { return d.ordem === c ? ' <i class="ti ti-chevron-' + (d.direcao === 'desc' ? 'down' : 'up') + '"></i>' : ''; };
        var h = '<div class="dashboard-lista-topo"><input type="search" class="form-control form-control-sm dashboard-busca" placeholder="Pesquisar título ou nº..." value="' + D.esc(opcoes.busca || '') + '" data-busca>'
            + '<span class="dashboard-pequeno">' + num(d.total) + ' item(ns)' + (d.truncado ? ' (limite de leitura atingido; refine os filtros)' : '') + '</span></div>';
        if (!d.linhas.length) {
            return h + '<div class="dashboard-widget-aviso"><i class="ti ti-mood-empty"></i><span>Nenhum item.</span></div>';
        }
        h += '<div class="dashboard-tabela-rolagem"><table class="table table-sm table-hover dashboard-tabela"><thead><tr>' + d.colunas.map(function (c) {
            return '<th data-ordenar="' + c.chave + '">' + D.esc(c.rotulo) + seta(c.chave) + '</th>';
        }).join('') + '</tr></thead><tbody>';
        d.linhas.forEach(function (l) {
            h += '<tr>' + d.colunas.map(function (c) {
                switch (c.chave) {
                    case 'modulo': return '<td class="text-nowrap"><i class="' + D.esc(l.icone) + ' text-muted"></i> ' + D.esc(l.modulo) + '</td>';
                    case 'id': return '<td><a href="' + D.esc(l.url) + '" target="_blank" rel="noopener">' + l.id + '</a></td>';
                    case 'titulo': return '<td class="dashboard-col-titulo"><a href="' + D.esc(l.url) + '" target="_blank" rel="noopener">' + D.esc(l.titulo) + '</a></td>';
                    case 'status': return '<td class="text-nowrap">' + (l.status_html || '') + ' ' + D.esc(l.status) + '</td>';
                    case 'prazo': return '<td class="text-nowrap' + (l.estourado ? ' dashboard-estourado' : '') + '">' + D.esc(l.prazo) + (l.estourado ? ' <i class="ti ti-alert-triangle" title="Prazo estourado"></i>' : '') + '</td>';
                    case 'abertura': case 'solucao': case 'idade': return '<td class="text-nowrap">' + D.esc(l[c.chave]) + '</td>';
                    default: return '<td>' + D.esc(l[c.chave]) + '</td>';
                }
            }).join('') + '</tr>';
        });
        h += '</tbody></table></div>';
        if (d.paginas > 1) {
            h += '<div class="dashboard-paginacao"><button type="button" class="btn btn-sm btn-ghost-secondary" data-pagina="' + (d.pagina - 1) + '"' + (d.pagina <= 1 ? ' disabled' : '') + '><i class="ti ti-chevron-left"></i></button>'
                + '<span class="dashboard-pequeno">Página ' + d.pagina + ' de ' + d.paginas + '</span>'
                + '<button type="button" class="btn btn-sm btn-ghost-secondary" data-pagina="' + (d.pagina + 1) + '"' + (d.pagina >= d.paginas ? ' disabled' : '') + '><i class="ti ti-chevron-right"></i></button></div>';
        }
        return h;
    };

    /** Liga busca, ordenação e paginação de uma tabela de itens; recarregar(opcoes) devolve uma Promise */
    D.ligarTabela = function (alvo, d, opcoes, recarregar) {
        alvo.innerHTML = D.tabelaItens(d, opcoes);
        var ir = function (mudanca) {
            var novas = Object.assign({}, opcoes, mudanca);
            alvo.classList.add('dashboard-atualizando');
            recarregar(novas).then(function (nd) {
                alvo.classList.remove('dashboard-atualizando');
                if (nd) {
                    D.ligarTabela(alvo, nd, novas, recarregar);
                }
            });
        };
        var t = null;
        var busca = alvo.querySelector('[data-busca]');
        busca.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(function () { ir({ busca: busca.value.trim(), pagina: 1 }); }, 400);
        });
        alvo.querySelectorAll('[data-ordenar]').forEach(function (th) {
            th.addEventListener('click', function () {
                ir({ ordem: th.dataset.ordenar, direcao: d.ordem === th.dataset.ordenar && d.direcao === 'desc' ? 'asc' : 'desc', pagina: 1 });
            });
        });
        alvo.querySelectorAll('[data-pagina]').forEach(function (b) {
            b.addEventListener('click', function () { ir({ pagina: Number(b.dataset.pagina) }); });
        });
    };

    function renderLista(corpo, w, d) {
        D.ligarTabela(corpo, d, { pagina: 1 }, function (op) {
            return D.get('lista', Object.assign({ painel: P.id, widget: w.id, f: D.filtros }, op)).then(function (r) {
                if (!r.success) {
                    D.aviso(r.mensagem, true);
                    return null;
                }
                return r.dados;
            });
        });
    }

    // ------------------------------------------------------------------ interações dos widgets

    grade.addEventListener('click', function (e) {
        var card = e.target.closest('[data-widget]');
        if (!card || D.editando && e.target.closest('[data-edicao]')) {
            return;
        }
        var w = P.widgets.find(function (x) { return x.id === card.dataset.widget; });
        var d = D.dados[w.id];
        var botao = e.target.closest('[data-acao]');
        if (botao) {
            ({ ampliar: D.ampliar, png: D.png, csv: D.csv })[botao.dataset.acao](w, d, card);
            return;
        }
        var det = e.target.closest('[data-detalhar]');
        if (det && !D.editando) {
            e.preventDefault();
            D.detalhar(w, det.dataset.detalhar, det.dataset.rotulo || '', false, w.tipo === 'tecnicos' ? 'abertos' : w.metricas[0]);
        }
    });

    D.detalhar = function (w, chave, rotulo, comparacao, metrica) {
        var m = D.modal({
            titulo: 'Itens: ' + w.titulo + (rotulo ? ' · ' + rotulo : '') + (comparacao ? ' (período de comparação)' : ''), icone: 'ti ti-list-search', tamanho: 'xl',
            corpo: '<div data-tabela><div class="dashboard-carregando"><span class="dashboard-giro"></span></div></div>',
        });
        m.mostrar();
        var buscar = function (op) {
            return D.get('detalhar', Object.assign({ painel: P.id, widget: w.id, f: D.filtros, chave: chave === undefined || chave === null ? '' : chave, comparacao: comparacao ? 1 : '', metrica: metrica || '' }, op)).then(function (r) {
                if (!r.success) {
                    D.aviso(r.mensagem, true);
                    return null;
                }
                return r.dados;
            });
        };
        buscar({ pagina: 1 }).then(function (d) {
            if (d) {
                D.ligarTabela(m.corpo.querySelector('[data-tabela]'), d, { pagina: 1 }, buscar);
            }
        });
    };

    D.ampliar = function (w, d) {
        if (!d || d.erro || d.aviso) {
            return;
        }
        var tabela = '';
        if (w.tipo === 'grafico') {
            tabela = '<table class="table table-sm dashboard-tabela"><thead><tr><th></th>' + d.series.map(function (s) { return '<th class="text-end">' + D.esc(s.nome) + '</th>'; }).join('')
                + (d.comparacao ? '<th class="text-end">Comparação</th>' : '') + '</tr></thead><tbody>'
                + d.categorias.map(function (c, i) {
                    return '<tr><td>' + D.esc(c) + '</td>' + d.series.map(function (s) { return '<td class="text-end">' + D.esc(s.formatados[i]) + '</td>'; }).join('')
                        + (d.comparacao ? '<td class="text-end">' + num(d.comparacao.valores[i]) + '</td>' : '') + '</tr>';
                }).join('') + '</tbody></table>';
        }
        var m = D.modal({
            titulo: w.titulo, icone: 'ti ti-arrows-maximize', tamanho: 'xl',
            corpo: '<div class="dashboard-ampliado"><div class="dashboard-ampliado-grafico" data-dashboard-grafico-modal></div>' + (tabela ? '<div class="dashboard-ampliado-tabela">' + tabela + '</div>' : '') + '</div>',
            aoMostrar: function (modal) {
                var g = window.echarts.init(modal.corpo.querySelector('[data-dashboard-grafico-modal]'));
                g.setOption(w.tipo === 'calor' ? D.opcaoCalor(d) : D.opcaoGrafico(w, d, true));
            },
        });
        m.mostrar();
    };

    var baixar = function (nome, url) {
        var a = document.createElement('a');
        a.href = url;
        a.download = nome;
        document.body.appendChild(a);
        a.click();
        a.remove();
    };

    var nomeArquivo = function (w, ext) {
        return (P.name + ' - ' + w.titulo).replace(/[\\/:*?"<>|]+/g, '_').slice(0, 120) + '.' + ext;
    };

    D.png = function (w) {
        var g = D.graficos[w.id];
        if (g) {
            baixar(nomeArquivo(w, 'png'), g.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: '#fff' }));
        }
    };

    D.csv = function (w, d) {
        if (!d || d.erro || d.aviso) {
            return;
        }
        var linhas = [];
        var celula = function (v) { return '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"'; };
        if (w.tipo === 'grafico') {
            linhas.push([''].concat(d.series.map(function (s) { return s.nome; })));
            d.categorias.forEach(function (c, i) { linhas.push([c].concat(d.series.map(function (s) { return s.formatados[i]; }))); });
        } else if (w.tipo === 'ranking') {
            linhas.push(['Posição', 'Nome', 'Valor']);
            d.itens.forEach(function (i, n) { linhas.push([n + 1, i.rotulo, i.formatado]); });
        } else if (w.tipo === 'tecnicos') {
            linhas.push(['Técnico'].concat(d.colunas.map(function (c) { return c.rotulo; })));
            d.linhas.forEach(function (l) { linhas.push([l.nome].concat(d.colunas.map(function (c) { return (l.valores[c.chave] || {}).f || ''; }))); });
        } else if (w.tipo === 'calor') {
            linhas.push(['Dia', 'Hora', 'Valor']);
            d.valores.forEach(function (v) { linhas.push([d.dias[v[1]], d.horas[v[0]], v[2]]); });
        } else if (w.tipo === 'lista') {
            linhas.push(d.colunas.map(function (c) { return c.rotulo; }));
            d.linhas.forEach(function (l) { linhas.push(d.colunas.map(function (c) { return l[c.chave]; })); });
        }
        var texto = '﻿' + linhas.map(function (l) { return l.map(celula).join(';'); }).join('\r\n');
        baixar(nomeArquivo(w, 'csv'), URL.createObjectURL(new Blob([texto], { type: 'text/csv;charset=utf-8' })));
    };

    // ------------------------------------------------------------------ PDF do painel

    var carregarScript = function (url) {
        return new Promise(function (ok, falha) {
            if (document.querySelector('script[src="' + url + '"]')) {
                ok();
                return;
            }
            var s = document.createElement('script');
            s.src = url;
            s.onload = ok;
            s.onerror = falha;
            document.head.appendChild(s);
        });
    };

    /** Gera o PDF (A4 paisagem, várias páginas) e devolve o Blob */
    D.gerarPdf = function () {
        return Promise.all([
            carregarScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js'),
            carregarScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'),
        ]).then(function () {
            pagina.classList.add('dashboard-exportando');
            var cabecalho = document.createElement('div');
            cabecalho.className = 'dashboard-pdf-cabecalho';
            cabecalho.innerHTML = '<strong>' + D.esc(P.name) + '</strong><span>' + D.esc((D.periodoTexto || '') + (D.comparacaoTexto ? ' · comparado com ' + D.comparacaoTexto : '')) + ' · gerado em ' + D.esc(new Date().toLocaleString('pt-BR')) + '</span>';
            grade.parentNode.insertBefore(cabecalho, grade);
            return window.html2canvas(grade, { scale: 1.5, useCORS: true, backgroundColor: '#ffffff', windowWidth: grade.scrollWidth }).then(function (canvas) {
                return window.html2canvas(cabecalho, { scale: 1.5, backgroundColor: '#ffffff' }).then(function (topo) {
                    cabecalho.remove();
                    pagina.classList.remove('dashboard-exportando');
                    var pdf = new window.jspdf.jsPDF('l', 'mm', 'a4');
                    var margem = 8;
                    var largura = 297 - margem * 2;
                    var altura = 210 - margem * 2;
                    var topoAlt = topo.height * largura / topo.width;
                    var pxMm = canvas.width / largura;
                    var y = 0;
                    var n = 0;
                    while (y < canvas.height) {
                        var disponivel = (n === 0 ? altura - topoAlt - 3 : altura) * pxMm;
                        var fatia = Math.min(disponivel, canvas.height - y);
                        var parte = document.createElement('canvas');
                        parte.width = canvas.width;
                        parte.height = fatia;
                        parte.getContext('2d').drawImage(canvas, 0, y, canvas.width, fatia, 0, 0, canvas.width, fatia);
                        if (n > 0) {
                            pdf.addPage();
                        }
                        var inicio = margem;
                        if (n === 0) {
                            pdf.addImage(topo.toDataURL('image/jpeg', 0.9), 'JPEG', margem, margem, largura, topoAlt);
                            inicio += topoAlt + 3;
                        }
                        pdf.addImage(parte.toDataURL('image/jpeg', 0.82), 'JPEG', margem, inicio, largura, fatia / pxMm);
                        pdf.setFontSize(8);
                        pdf.setTextColor(150);
                        pdf.text('Página ' + (n + 1), 297 - margem, 210 - 3, { align: 'right' });
                        y += fatia;
                        n++;
                    }
                    return pdf.output('blob');
                });
            });
        }).catch(function (e) {
            pagina.classList.remove('dashboard-exportando');
            var c = pagina.querySelector('.dashboard-pdf-cabecalho');
            if (c) {
                c.remove();
            }
            throw e;
        });
    };

    var nomePdf = function () {
        return (P.name + ' ' + new Date().toISOString().slice(0, 10)).replace(/[\\/:*?"<>|]+/g, '_') + '.pdf';
    };

    pagina.querySelector('[data-dashboard-pdf]').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        D.aviso('Gerando o PDF...');
        D.gerarPdf().then(function (blob) {
            baixar(nomePdf(), URL.createObjectURL(blob));
        }).catch(function () {
            D.aviso('Não foi possível gerar o PDF (as bibliotecas de PDF não carregaram).', true);
        }).then(function () { btn.disabled = false; });
    });

    // ------------------------------------------------------------------ e-mail

    pagina.querySelector('[data-dashboard-email]').addEventListener('click', function () {
        var usuarios = (base.usuarios || []).map(function (u) { return { valor: u.id, rotulo: u.nome + ' <' + u.email + '>' }; });
        var m = D.modal({
            titulo: 'Enviar o painel por e-mail', icone: 'ti ti-mail', tamanho: 'lg',
            corpo: (base.remetente ? '' : '<div class="dashboard-alerta dashboard-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>O GLPI não tem remetente de e-mail configurado: o envio vai falhar até um administrador configurar.</span></div>')
                + '<div class="dashboard-campo"><label>Usuários</label>' + D.criarMs('usuarios', usuarios, [], 'Escolha usuários do GLPI') + '</div>'
                + '<div class="dashboard-campo"><label>Outros e-mails</label><input type="text" class="form-control form-control-sm" data-emails placeholder="Separados por vírgula ou ponto e vírgula"></div>'
                + '<div class="dashboard-campo"><label>Assunto</label><input type="text" class="form-control form-control-sm" data-assunto maxlength="200" value="' + D.esc('Dashboard: ' + P.name + ' - ' + (D.periodoTexto || '')) + '"></div>'
                + '<div class="dashboard-campo"><label>Mensagem</label><textarea data-mensagem></textarea></div>'
                + '<label class="dashboard-opcao"><input type="checkbox" class="dashboard-check" data-anexar checked> Anexar o PDF do painel (com os gráficos)</label>'
                + '<p class="dashboard-explicacao"><i class="ti ti-info-circle"></i><span>O e-mail leva a mensagem, os filtros aplicados e os dados de cada widget em tabelas.</span></p>',
            botoes: [{
                rotulo: 'Enviar', icone: 'ti ti-send', acao: function (modal, btn) {
                    var c = modal.corpo;
                    var fd = new FormData();
                    fd.append('painel', P.id);
                    fd.append('f', JSON.stringify(D.filtros));
                    D.valoresMs(c.querySelector('[data-dashboard-ms]')).forEach(function (v) { fd.append('usuarios[]', v); });
                    fd.append('emails', c.querySelector('[data-emails]').value);
                    fd.append('assunto', c.querySelector('[data-assunto]').value);
                    fd.append('mensagem', D.valorEditor(c.querySelector('[data-mensagem]')));
                    if (!D.valoresMs(c.querySelector('[data-dashboard-ms]')).length && !c.querySelector('[data-emails]').value.trim()) {
                        D.aviso('Escolha ao menos um destinatário.', true);
                        return;
                    }
                    btn.disabled = true;
                    btn.querySelector('span').textContent = 'Enviando...';
                    var envio = c.querySelector('[data-anexar]').checked
                        ? D.gerarPdf().then(function (blob) { fd.append('pdf', blob, nomePdf()); fd.append('pdf_nome', nomePdf()); }).catch(function () { D.aviso('PDF indisponível: enviando só o resumo.'); })
                        : Promise.resolve();
                    envio.then(function () { return D.post('enviar', fd); }).then(function (r) {
                        btn.disabled = false;
                        btn.querySelector('span').textContent = 'Enviar';
                        D.aviso(r.mensagem, !r.success);
                        if (r.success) {
                            modal.fechar();
                        }
                    });
                },
            }],
            aoMostrar: function (modal) { D.editor(modal.corpo.querySelector('[data-mensagem]'), 160); },
        });
        D.iniciarMs(m.corpo);
        m.mostrar();
    });

    // ------------------------------------------------------------------ histórico, agendamentos, configurações

    var menuAcao = function (seletor, fn) {
        var el = pagina.querySelector(seletor);
        if (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                fn();
            });
        }
    };

    menuAcao('[data-dashboard-historico]', function () {
        var m = D.modal({ titulo: 'Histórico de envios', icone: 'ti ti-history', tamanho: 'xl', corpo: '<div class="dashboard-carregando"><span class="dashboard-giro"></span></div>' });
        m.mostrar();
        D.get('envios', { painel: P.id }).then(function (r) {
            if (!r.success) {
                m.corpo.innerHTML = '<div class="dashboard-widget-aviso">' + D.esc(r.mensagem) + '</div>';
                return;
            }
            m.corpo.innerHTML = !r.envios.length ? '<div class="dashboard-vazio">Nenhum envio deste painel.</div>'
                : '<div class="dashboard-tabela-rolagem"><table class="table table-sm table-hover dashboard-tabela"><thead><tr><th>Data</th><th>Tipo</th><th>Por</th><th>Período</th><th>Destinatários</th><th>Resultado</th></tr></thead><tbody>'
                + r.envios.map(function (x) {
                    return '<tr><td class="text-nowrap">' + D.esc(x.data) + '</td><td>' + D.esc(x.tipo) + '</td><td>' + D.esc(x.por) + '</td><td>' + D.esc(x.periodo) + '</td><td>' + D.esc(x.destinatarios) + '</td>'
                        + '<td class="text-nowrap"><span class="dashboard-selo dashboard-selo-' + (x.ok ? 'ok' : 'erro') + '" title="' + D.esc(x.erro) + '">' + D.esc(x.resultado) + '</span></td></tr>';
                }).join('') + '</tbody></table></div>';
        });
    });

    menuAcao('[data-dashboard-agendamentos]', function () {
        var m = D.modal({ titulo: 'Envios agendados', icone: 'ti ti-calendar-time', tamanho: 'xl', corpo: '<div data-lista></div><div data-form hidden></div>' });
        var info = null;
        var desenhar = function (lista) {
            var alvo = m.corpo.querySelector('[data-lista]');
            alvo.innerHTML = '<div class="dashboard-lista-topo"><span class="dashboard-explicacao"><i class="ti ti-info-circle"></i><span>A tarefa automática "DashboardEnviarRelatorios" verifica de hora em hora. O e-mail agendado leva os dados em tabelas (o PDF com gráficos só existe no envio feito pela tela).</span></span>'
                + '<button type="button" class="btn btn-sm dashboard-btn-principal" data-novo-ag><i class="ti ti-plus"></i><span>Novo agendamento</span></button></div>'
                + (!lista.length ? '<div class="dashboard-vazio">Nenhum envio agendado.</div>'
                    : '<table class="table table-sm table-hover dashboard-tabela"><thead><tr><th>Nome</th><th>Quando</th><th>Destinatários</th><th>Último envio</th><th>Situação</th><th class="text-end">Ações</th></tr></thead><tbody>'
                    + lista.map(function (a) {
                        return '<tr><td>' + D.esc(a.name) + '</td><td>' + D.esc(a.resumo) + '</td><td>' + (a.usuarios.length + a.emails.length) + '</td><td>' + D.esc(a.ultimo) + '</td>'
                            + '<td><span class="dashboard-selo dashboard-selo-' + (Number(a.is_active) ? 'ok' : 'neutro') + '">' + (Number(a.is_active) ? 'Ativo' : 'Pausado') + '</span></td>'
                            + '<td class="text-end"><span class="dashboard-botoes"><button type="button" class="btn btn-sm btn-ghost-secondary" data-enviar-ag="' + a.id + '" title="Enviar agora"><i class="ti ti-send"></i></button>'
                            + '<button type="button" class="btn btn-sm btn-ghost-secondary" data-editar-ag="' + a.id + '" title="Editar"><i class="ti ti-edit"></i></button>'
                            + '<button type="button" class="btn btn-sm btn-ghost-danger" data-excluir-ag="' + a.id + '" title="Excluir"><i class="ti ti-trash"></i></button></span></td></tr>';
                    }).join('') + '</tbody></table>');
            alvo.hidden = false;
            m.corpo.querySelector('[data-form]').hidden = true;
            alvo.querySelector('[data-novo-ag]').addEventListener('click', function () { formulario({}); });
            alvo.querySelectorAll('[data-editar-ag]').forEach(function (b) {
                b.addEventListener('click', function () { formulario(lista.find(function (a) { return String(a.id) === b.dataset.editarAg; })); });
            });
            alvo.querySelectorAll('[data-excluir-ag]').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (b.dataset.confirmado !== '1') {
                        b.dataset.confirmado = '1';
                        b.innerHTML = '<i class="ti ti-check"></i> Confirmar';
                        return;
                    }
                    D.post('agendamento_excluir', { painel: P.id, agendamento: b.dataset.excluirAg }).then(function (r) {
                        r.success ? desenhar(r.agendamentos) : D.aviso(r.mensagem, true);
                    });
                });
            });
            alvo.querySelectorAll('[data-enviar-ag]').forEach(function (b) {
                b.addEventListener('click', function () {
                    b.disabled = true;
                    D.post('agendamento_enviar', { painel: P.id, agendamento: b.dataset.enviarAg }).then(function (r) {
                        D.aviso(r.mensagem, !r.success);
                        if (r.agendamentos) {
                            desenhar(r.agendamentos);
                        }
                    });
                });
            });
        };
        var formulario = function (a) {
            var usuarios = (base.usuarios || []).map(function (u) { return { valor: u.id, rotulo: u.nome + ' <' + u.email + '>' }; });
            var op = function (obj, sel) { return Object.keys(obj).map(function (k) { return '<option value="' + k + '"' + (String(k) === String(sel) ? ' selected' : '') + '>' + D.esc(obj[k]) + '</option>'; }).join(''); };
            var periodos = {};
            Object.keys(base.catalogo.periodos || {}).forEach(function (k) { periodos[k] = base.catalogo.periodos[k]; });
            var horas = {};
            for (var h = 0; h < 24; h++) {
                horas[h] = String(h).padStart(2, '0') + 'h';
            }
            var alvo = m.corpo.querySelector('[data-form]');
            alvo.innerHTML = '<div class="dashboard-linha"><div class="dashboard-campo"><label>Nome</label><input type="text" class="form-control form-control-sm" data-c="name" value="' + D.esc(a.name || '') + '" placeholder="Ex.: Relatório semanal da diretoria"></div>'
                + '<div class="dashboard-campo"><label>Frequência</label><select class="form-select form-select-sm" data-c="frequencia">' + op(info.frequencias, a.frequencia || 'semanal') + '</select></div>'
                + '<div class="dashboard-campo" data-campo-dia><label>Dia</label><select class="form-select form-select-sm" data-c="dia"></select></div>'
                + '<div class="dashboard-campo"><label>Hora</label><select class="form-select form-select-sm" data-c="hora">' + op(horas, a.hora === undefined ? 8 : a.hora) + '</select></div>'
                + '<div class="dashboard-campo"><label>Período dos dados</label><select class="form-select form-select-sm" data-c="periodo">' + op(periodos, a.periodo || '7d') + '</select></div></div>'
                + '<div class="dashboard-linha"><div class="dashboard-campo"><label>Usuários</label>' + D.criarMs('usuarios', usuarios, a.usuarios || [], 'Escolha usuários do GLPI') + '</div>'
                + '<div class="dashboard-campo"><label>Outros e-mails</label><input type="text" class="form-control form-control-sm" data-c="emails" value="' + D.esc((a.emails || []).join(', ')) + '"></div></div>'
                + '<div class="dashboard-campo"><label>Mensagem</label><textarea data-c="mensagem">' + D.esc(a.mensagem || '') + '</textarea></div>'
                + '<div class="form-check form-switch dashboard-switch"><input class="form-check-input" type="checkbox" id="dashboard-ag-ativo" data-c="is_active"' + (a.id && !Number(a.is_active) ? '' : ' checked') + '><label class="form-check-label" for="dashboard-ag-ativo">Ativo</label></div>'
                + '<div class="dashboard-rodape-form"><button type="button" class="btn btn-sm dashboard-btn-cancelar" data-voltar><i class="ti ti-arrow-left"></i><span>Voltar</span></button>'
                + '<button type="button" class="btn btn-sm dashboard-btn-principal" data-salvar><i class="ti ti-device-floppy"></i><span>Salvar agendamento</span></button></div>';
            var freq = alvo.querySelector('[data-c="frequencia"]');
            var dias = function () {
                var sel = alvo.querySelector('[data-c="dia"]');
                var atual = sel.value || a.dia || 1;
                if (freq.value === 'semanal') {
                    sel.innerHTML = op(info.dias, atual);
                } else {
                    var lista = {};
                    for (var i = 1; i <= 31; i++) {
                        lista[i] = 'Dia ' + i;
                    }
                    sel.innerHTML = op(lista, atual);
                }
                alvo.querySelector('[data-campo-dia]').hidden = freq.value === 'diario';
            };
            freq.addEventListener('change', dias);
            dias();
            D.iniciarMs(alvo);
            D.editor(alvo.querySelector('[data-c="mensagem"]'), 140);
            m.corpo.querySelector('[data-lista]').hidden = true;
            alvo.hidden = false;
            alvo.querySelector('[data-voltar]').addEventListener('click', function () {
                D.removerEditores(alvo);
                carregar();
            });
            alvo.querySelector('[data-salvar]').addEventListener('click', function () {
                var dados = { painel: P.id, agendamento: a.id || 0, usuarios: D.valoresMs(alvo.querySelector('[data-dashboard-ms]')) };
                alvo.querySelectorAll('[data-c]').forEach(function (c) {
                    dados[c.dataset.c] = c.type === 'checkbox' ? (c.checked ? 1 : 0) : (c.tagName === 'TEXTAREA' ? D.valorEditor(c) : c.value);
                });
                D.post('agendamento_salvar', dados).then(function (r) {
                    if (!r.success) {
                        D.aviso(r.mensagem, true);
                        return;
                    }
                    D.removerEditores(alvo);
                    D.aviso('Agendamento salvo.');
                    desenhar(r.agendamentos);
                });
            });
        };
        var carregar = function () {
            D.get('agendamentos', { painel: P.id }).then(function (r) {
                if (!r.success) {
                    D.aviso(r.mensagem, true);
                    return;
                }
                info = r;
                desenhar(r.agendamentos);
            });
        };
        m.mostrar();
        carregar();
    });

    menuAcao('[data-dashboard-config-painel]', function () {
        var vis = Object.keys(base.visibilidades).map(function (k) { return '<option value="' + k + '"' + (P.visibilidade === k ? ' selected' : '') + '>' + D.esc(base.visibilidades[k]) + '</option>'; }).join('');
        var atu = Object.keys(base.atualizacoes).map(function (k) { return '<option value="' + k + '"' + (String(P.atualizacao) === k ? ' selected' : '') + '>' + D.esc(base.atualizacoes[k]) + '</option>'; }).join('');
        var perfis = Object.keys(base.perfis).map(function (k) { return { valor: k, rotulo: base.perfis[k] }; });
        var m = D.modal({
            titulo: 'Configurações do painel', icone: 'ti ti-settings',
            corpo: '<div class="dashboard-campo"><label>Nome</label><input type="text" class="form-control form-control-sm" data-nome maxlength="255" value="' + D.esc(P.name) + '"></div>'
                + '<div class="dashboard-campo"><label>Quem pode ver</label><select class="form-select form-select-sm" data-vis>' + vis + '</select></div>'
                + '<div class="dashboard-campo" data-perfis' + (P.visibilidade === 'perfis' ? '' : ' hidden') + '><label>Perfis</label>' + D.criarMs('perfis', perfis, P.perfis, 'Escolha os perfis') + '</div>'
                + '<div class="dashboard-campo"><label>Atualização automática</label><select class="form-select form-select-sm" data-atu>' + atu + '</select><small>Os dados são recalculados sozinhos enquanto a aba estiver visível.</small></div>',
            botoes: [{
                rotulo: 'Salvar', icone: 'ti ti-device-floppy', acao: function (modal) {
                    var c = modal.corpo;
                    D.post('painel_salvar', { id: P.id, name: c.querySelector('[data-nome]').value, visibilidade: c.querySelector('[data-vis]').value,
                        perfis: D.valoresMs(c.querySelector('[data-dashboard-ms]')), atualizacao: c.querySelector('[data-atu]').value }).then(function (r) {
                        if (!r.success) {
                            D.aviso(r.mensagem, true);
                            return;
                        }
                        P.name = r.painel.name;
                        P.visibilidade = r.painel.visibilidade;
                        P.perfis = r.painel.perfis;
                        P.atualizacao = Number(r.painel.atualizacao);
                        pagina.querySelector('[data-dashboard-nome]').textContent = P.name;
                        agendarAtualizacao();
                        D.aviso('Painel salvo.');
                        modal.fechar();
                    });
                },
            }],
        });
        m.corpo.querySelector('[data-vis]').addEventListener('change', function (e) { m.corpo.querySelector('[data-perfis]').hidden = e.target.value !== 'perfis'; });
        D.iniciarMs(m.corpo);
        m.mostrar();
    });

    menuAcao('[data-dashboard-salvar-filtros]', function () {
        D.post('painel_salvar', { id: P.id, filtros: D.filtros }).then(function (r) {
            if (r.success) {
                P.filtros = r.painel.filtros;
            }
            D.aviso(r.success ? 'Estes filtros passam a ser o padrão do painel.' : r.mensagem, !r.success);
        });
    });

    menuAcao('[data-dashboard-duplicar]', function () {
        D.post('painel_duplicar', { id: P.id }).then(function (r) {
            r.success ? (window.location.href = r.url) : D.aviso(r.mensagem, true);
        });
    });

    menuAcao('[data-dashboard-excluir]', function () {
        D.confirmar('Excluir painel', 'O painel <strong>' + D.esc(P.name) + '</strong> e os envios agendados dele serão excluídos.', 'Excluir', function () {
            D.post('painel_excluir', { id: P.id }).then(function (r) {
                r.success ? (window.location.href = r.url) : D.aviso(r.mensagem, true);
            });
        });
    });

    pagina.querySelector('[data-dashboard-atualizar]').addEventListener('click', function () { D.carregar(); });

    // ------------------------------------------------------------------ tempo real

    var agendarAtualizacao = function () {
        clearInterval(timer);
        if (P.atualizacao > 0) {
            timer = setInterval(function () {
                if (!document.hidden && !D.editando && !document.querySelector('.dashboard-modal.show')) {
                    D.carregar();
                }
            }, P.atualizacao * 1000);
        }
    };

    var redimensionar = null;
    window.addEventListener('resize', function () {
        clearTimeout(redimensionar);
        redimensionar = setTimeout(function () {
            Object.keys(D.graficos).forEach(function (k) { D.graficos[k].resize(); });
        }, 150);
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && P.atualizacao > 0) {
            D.carregar();
        }
    });

    D.iniciarMs(pagina);
    contarExtras();
    if (pagina.querySelectorAll('[data-dashboard-filtro] .dashboard-ms-opcao input:checked').length) {
        pagina.querySelector('[data-dashboard-extras]').hidden = false;
    }
    D.renderGrade();
    D.carregar();
    agendarAtualizacao();
})();
