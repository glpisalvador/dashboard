/* Plugin Dashboard - editor do layout: modo de edição, arrastar para reordenar, largura e altura,
 * duplicar, remover e o assistente de widget com pré-visualização. Usa window.dashboard. */
(function () {
    'use strict';

    var D = window.dashboard;
    var pagina = document.querySelector('[data-dashboard-pagina]');
    if (!D || !pagina || !D.base.painel || !D.base.painel.editar) {
        return;
    }
    var P = D.base.painel;
    var cat = D.base.catalogo;
    var grade = pagina.querySelector('[data-dashboard-grade]');
    var aviso = pagina.querySelector('[data-dashboard-aviso-edicao]');
    var LARGURAS = [2, 3, 4, 6, 8, 9, 12];
    var ROTULOS_LARGURA = { 2: '1/6', 3: '1/4', 4: '1/3', 6: 'Metade', 8: '2/3', 9: '3/4', 12: 'Inteira' };
    var ALTURAS = { p: 'Baixa', m: 'Média', g: 'Alta' };

    var salvar = function () {
        return D.post('painel_salvar', { id: P.id, widgets: P.widgets }).then(function (r) {
            if (!r.success) {
                D.aviso(r.mensagem, true);
                return false;
            }
            P.widgets = r.painel.widgets;
            D.renderGrade();
            D.carregar();
            return true;
        });
    };

    // ------------------------------------------------------------------ modo de edição

    var controles = function () {
        grade.querySelectorAll('[data-widget]').forEach(function (card) {
            var alvo = card.querySelector('[data-edicao]');
            card.draggable = D.editando;
            if (!D.editando) {
                alvo.innerHTML = '';
                return;
            }
            alvo.innerHTML = '<button type="button" class="dashboard-icone" data-ed="menor" title="Mais estreito"><i class="ti ti-arrow-bar-to-left"></i></button>'
                + '<button type="button" class="dashboard-icone" data-ed="maior" title="Mais largo"><i class="ti ti-arrow-bar-to-right"></i></button>'
                + '<button type="button" class="dashboard-icone" data-ed="altura" title="Altura"><i class="ti ti-arrows-vertical"></i></button>'
                + '<button type="button" class="dashboard-icone" data-ed="editar" title="Editar"><i class="ti ti-edit"></i></button>'
                + '<button type="button" class="dashboard-icone" data-ed="duplicar" title="Duplicar"><i class="ti ti-copy"></i></button>'
                + '<button type="button" class="dashboard-icone dashboard-icone-perigo" data-ed="remover" title="Remover"><i class="ti ti-trash"></i></button>';
        });
    };
    D.aoRenderizar = controles;

    var alternar = function (ligar) {
        D.editando = ligar;
        aviso.hidden = !ligar;
        grade.classList.toggle('dashboard-editando', ligar);
        controles();
    };

    pagina.querySelector('[data-dashboard-editar]').addEventListener('click', function () { alternar(!D.editando); });
    pagina.querySelector('[data-dashboard-concluir]').addEventListener('click', function () { alternar(false); });
    pagina.querySelector('[data-dashboard-adicionar]').addEventListener('click', function () { assistente(null); });

    grade.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ed]');
        if (!b || !D.editando) {
            return;
        }
        var card = b.closest('[data-widget]');
        var i = P.widgets.findIndex(function (w) { return w.id === card.dataset.widget; });
        var w = P.widgets[i];
        switch (b.dataset.ed) {
            case 'menor':
            case 'maior': {
                var pos = LARGURAS.indexOf(w.largura);
                var nova = LARGURAS[Math.max(0, Math.min(LARGURAS.length - 1, pos + (b.dataset.ed === 'maior' ? 1 : -1)))];
                if (nova !== w.largura) {
                    w.largura = nova;
                    salvar();
                }
                break;
            }
            case 'altura':
                w.altura = w.altura === 'p' ? 'm' : (w.altura === 'm' ? 'g' : 'p');
                salvar();
                break;
            case 'editar':
                assistente(w);
                break;
            case 'duplicar': {
                var copia = JSON.parse(JSON.stringify(w));
                delete copia.id;
                P.widgets.splice(i + 1, 0, copia);
                salvar();
                break;
            }
            case 'remover':
                if (b.dataset.confirmado !== '1') {
                    b.dataset.confirmado = '1';
                    b.classList.add('dashboard-confirmando');
                    b.innerHTML = '<i class="ti ti-check"></i> Remover?';
                    setTimeout(function () {
                        if (b.isConnected) {
                            b.dataset.confirmado = '';
                            b.classList.remove('dashboard-confirmando');
                            b.innerHTML = '<i class="ti ti-trash"></i>';
                        }
                    }, 4000);
                    return;
                }
                P.widgets.splice(i, 1);
                salvar();
                break;
        }
    });

    // ------------------------------------------------------------------ arrastar para reordenar

    var arrastado = null;
    grade.addEventListener('dragstart', function (e) {
        var card = e.target.closest('[data-widget]');
        if (!D.editando || !card) {
            return;
        }
        arrastado = card;
        card.classList.add('dashboard-arrastando');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.widget);
    });
    grade.addEventListener('dragover', function (e) {
        if (!arrastado) {
            return;
        }
        e.preventDefault();
        var alvo = e.target.closest('[data-widget]');
        if (!alvo || alvo === arrastado) {
            return;
        }
        var r = alvo.getBoundingClientRect();
        var depois = (e.clientY - r.top) / r.height > 0.5 || (e.clientX - r.left) / r.width > 0.5;
        grade.insertBefore(arrastado, depois ? alvo.nextSibling : alvo);
    });
    grade.addEventListener('dragend', function () {
        if (!arrastado) {
            return;
        }
        arrastado.classList.remove('dashboard-arrastando');
        arrastado = null;
        var ordem = Array.from(grade.querySelectorAll('[data-widget]')).map(function (c) { return c.dataset.widget; });
        var antes = P.widgets.map(function (w) { return w.id; }).join(',');
        if (ordem.join(',') !== antes) {
            P.widgets.sort(function (a, b) { return ordem.indexOf(a.id) - ordem.indexOf(b.id); });
            salvar();
        }
    });

    // ------------------------------------------------------------------ assistente de widget

    var op = function (obj, sel, filtro) {
        return Object.keys(obj).filter(function (k) { return !filtro || filtro(k); }).map(function (k) {
            var rotulo = typeof obj[k] === 'object' ? obj[k].rotulo : obj[k];
            return '<option value="' + k + '"' + (String(k) === String(sel) ? ' selected' : '') + '>' + D.esc(rotulo) + '</option>';
        }).join('');
    };

    function assistente(original) {
        var w = original ? JSON.parse(JSON.stringify(original)) : { tipo: 'grafico', metricas: ['abertos'], dimensao: 'tempo', grafico: 'barras', limite: 10, largura: 6, altura: 'm', colunas: [], titulo: '' };
        var tipos = Object.keys(cat.tipos).map(function (k) {
            return '<label class="dashboard-tipo"><input type="radio" name="tipo" value="' + k + '"' + (w.tipo === k ? ' checked' : '') + '><span><i class="' + cat.tipos[k].icone + '"></i>' + D.esc(cat.tipos[k].rotulo) + '</span></label>';
        }).join('');
        var m = D.modal({
            titulo: original ? 'Editar widget' : 'Adicionar widget', icone: 'ti ti-layout-grid-add', tamanho: 'xl',
            corpo: '<div class="dashboard-assistente"><div class="dashboard-assistente-form">'
                + '<div class="dashboard-campo"><label>Tipo</label><div class="dashboard-tipos">' + tipos + '</div></div>'
                + '<div class="dashboard-campo"><label>Título</label><input type="text" class="form-control form-control-sm" data-c="titulo" maxlength="120" placeholder="Automático" value="' + D.esc(original ? w.titulo : '') + '"></div>'
                + '<div data-blocos></div></div>'
                + '<div class="dashboard-assistente-previa"><div class="dashboard-pequeno"><i class="ti ti-eye"></i> Pré-visualização com os filtros atuais</div>'
                + '<div class="card dashboard-widget" data-previa><div class="dashboard-widget-topo"><span class="dashboard-widget-titulo" data-previa-titulo></span></div><div class="dashboard-widget-corpo" data-corpo style="height:300px"></div></div></div></div>',
            botoes: [{
                rotulo: original ? 'Salvar widget' : 'Adicionar ao painel', icone: 'ti ti-check', acao: function (modal, btn) {
                    var novo = ler();
                    if (!novo) {
                        return;
                    }
                    btn.disabled = true;
                    if (original) {
                        novo.id = original.id;
                        P.widgets[P.widgets.findIndex(function (x) { return x.id === original.id; })] = novo;
                    } else {
                        P.widgets.push(novo);
                    }
                    salvar().then(function (ok) {
                        btn.disabled = false;
                        if (ok) {
                            modal.fechar();
                            if (!original) {
                                setTimeout(function () { grade.lastElementChild && grade.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 300);
                            }
                        }
                    });
                },
            }],
        });
        var c = m.corpo;
        var blocos = c.querySelector('[data-blocos]');

        var desenhar = function () {
            var t = w.tipo;
            var h = '';
            var umaMetrica = t === 'indicador' || t === 'ranking' || t === 'calor';
            if (umaMetrica) {
                h += '<div class="dashboard-campo"><label>Métrica</label><select class="form-select form-select-sm" data-c="metrica">' + op(cat.metricas, w.metricas[0] || 'abertos', t === 'calor' ? function (k) { return cat.metricas[k].temporal; } : null) + '</select><small data-desc></small></div>';
            } else if (t === 'grafico' || t === 'tecnicos') {
                h += '<div class="dashboard-campo"><label>Métricas' + (t === 'grafico' ? ' (até 4, viram séries)' : '') + '</label><div class="dashboard-metricas">'
                    + Object.keys(cat.metricas).map(function (k) {
                        var me = cat.metricas[k];
                        return '<label class="dashboard-opcao" title="' + D.esc(me.descricao) + '"><input type="checkbox" class="dashboard-check" data-metrica value="' + k + '"' + (w.metricas.indexOf(k) >= 0 ? ' checked' : '') + '> ' + D.esc(me.rotulo)
                            + (me.temporal ? '' : ' <small>(atual)</small>') + '</label>';
                    }).join('') + '</div></div>';
            }
            if (t === 'grafico' || t === 'ranking') {
                h += '<div class="dashboard-linha"><div class="dashboard-campo"><label>Por</label><select class="form-select form-select-sm" data-c="dimensao">'
                    + op(cat.dimensoes, w.dimensao || (t === 'ranking' ? 'tecnico' : 'tempo'), t === 'ranking' ? function (k) { return k !== 'tempo'; } : null) + '</select><small data-dim></small></div>';
                if (t === 'grafico') {
                    h += '<div class="dashboard-campo"><label>Gráfico</label><select class="form-select form-select-sm" data-c="grafico">' + op(cat.graficos, w.grafico || 'barras') + '</select></div>';
                }
                h += '<div class="dashboard-campo"><label>Máximo de itens</label><input type="number" class="form-control form-control-sm" data-c="limite" min="3" max="50" value="' + (w.limite || 10) + '"></div></div>';
            }
            if (t === 'lista') {
                h += '<div class="dashboard-campo"><label>Colunas</label><div class="dashboard-metricas">' + Object.keys(cat.colunas).map(function (k) {
                    var marcado = (w.colunas && w.colunas.length ? w.colunas : ['modulo', 'id', 'titulo', 'entidade', 'status', 'tecnico', 'abertura', 'idade']).indexOf(k) >= 0;
                    return '<label class="dashboard-opcao"><input type="checkbox" class="dashboard-check" data-coluna value="' + k + '"' + (marcado ? ' checked' : '') + '> ' + D.esc(cat.colunas[k]) + '</label>';
                }).join('') + '</div></div>';
            }
            h += '<div class="dashboard-linha">';
            if (t !== 'tecnicos') {
                var larguras = {};
                (t === 'indicador' ? [2, 3, 4] : LARGURAS).forEach(function (l) { larguras[l] = ROTULOS_LARGURA[l]; });
                h += '<div class="dashboard-campo"><label>Largura</label><select class="form-select form-select-sm" data-c="largura">' + op(larguras, w.largura) + '</select></div>';
            }
            if (t === 'grafico' || t === 'calor') {
                h += '<div class="dashboard-campo"><label>Altura</label><select class="form-select form-select-sm" data-c="altura">' + op(ALTURAS, w.altura || 'm') + '</select></div>';
            }
            blocos.innerHTML = h + '</div>';
            dicas();
        };

        var dicas = function () {
            var sel = c.querySelector('[data-c="metrica"]');
            var desc = c.querySelector('[data-desc]');
            if (sel && desc) {
                var me = cat.metricas[sel.value];
                desc.textContent = me.descricao + ' Módulos: ' + me.modulos.map(function (x) { return cat.modulos[x].rotulo; }).join(', ') + '.';
            }
            var dim = c.querySelector('[data-c="dimensao"]');
            var dd = c.querySelector('[data-dim]');
            if (dim && dd) {
                dd.textContent = 'Vale para: ' + cat.dimensoes[dim.value].modulos.map(function (x) { return cat.modulos[x].rotulo; }).join(', ') + '.';
            }
        };

        var ler = function () {
            var novo = { tipo: c.querySelector('input[name="tipo"]:checked').value, titulo: c.querySelector('[data-c="titulo"]').value.trim() };
            var met = c.querySelector('[data-c="metrica"]');
            novo.metricas = met ? [met.value] : Array.from(c.querySelectorAll('[data-metrica]:checked')).map(function (x) { return x.value; });
            if (novo.tipo === 'grafico' && (novo.metricas.length < 1 || novo.metricas.length > 4)) {
                D.aviso('Escolha de 1 a 4 métricas para o gráfico.', true);
                return null;
            }
            ['dimensao', 'grafico', 'limite', 'largura', 'altura'].forEach(function (k) {
                var el = c.querySelector('[data-c="' + k + '"]');
                if (el) {
                    novo[k] = k === 'limite' || k === 'largura' ? Number(el.value) : el.value;
                }
            });
            novo.colunas = Array.from(c.querySelectorAll('[data-coluna]:checked')).map(function (x) { return x.value; });
            if (novo.tipo === 'grafico' && novo.dimensao === 'tempo' && novo.metricas.every(function (k) { return !cat.metricas[k].temporal; })) {
                D.aviso('Métricas de situação atual não têm evolução no tempo: escolha outra dimensão ou outra métrica.', true);
                return null;
            }
            novo.largura = novo.largura || (novo.tipo === 'indicador' ? 3 : 12);
            return novo;
        };

        var previaTimer = null;
        var previa = function () {
            clearTimeout(previaTimer);
            previaTimer = setTimeout(function () {
                var novo = ler();
                if (!novo) {
                    return;
                }
                var card = c.querySelector('[data-previa]');
                var corpo = card.querySelector('[data-corpo]');
                corpo.style.height = novo.tipo === 'indicador' ? '' : ({ p: 220, m: 300, g: 380 }[novo.altura || 'm']) + 'px';
                corpo.innerHTML = '<div class="dashboard-carregando"><span class="dashboard-giro"></span></div>';
                D.get('widget', { painel: P.id, w: novo, f: D.filtros }).then(function (r) {
                    if (!r.success) {
                        corpo.innerHTML = '<div class="dashboard-widget-aviso">' + D.esc(r.mensagem) + '</div>';
                        return;
                    }
                    card.querySelector('[data-previa-titulo]').textContent = r.widget.titulo;
                    var wp = Object.assign({}, r.widget, { id: '__previa__' });
                    card.dataset.widget = '__previa__';
                    D.renderWidget(card, wp, r.dados);
                });
            }, 300);
        };

        c.addEventListener('change', function (e) {
            if (e.target.name === 'tipo') {
                w.tipo = e.target.value;
                w.metricas = w.tipo === 'tecnicos' ? cat.tecnicos.slice() : (w.metricas.length ? [w.metricas[0]] : ['abertos']);
                if (w.tipo === 'indicador') {
                    w.largura = 3;
                } else if (w.tipo === 'tecnicos' || w.tipo === 'lista' || w.tipo === 'calor') {
                    w.largura = 12;
                } else if (w.largura < 4) {
                    w.largura = 6;
                }
                if (w.tipo === 'ranking' && (!w.dimensao || w.dimensao === 'tempo')) {
                    w.dimensao = 'tecnico';
                }
                desenhar();
            } else if (e.target.matches('[data-metrica]') && c.querySelectorAll('[data-metrica]:checked').length > 4 && w.tipo === 'grafico') {
                e.target.checked = false;
                D.aviso('No máximo 4 métricas por gráfico.', true);
                return;
            }
            dicas();
            previa();
        });
        c.querySelector('[data-c="titulo"]').addEventListener('input', previa);
        m.el.addEventListener('hidden.bs.modal', function () {
            var g = D.graficos.__previa__;
            if (g) {
                g.dispose();
                delete D.graficos.__previa__;
            }
        });
        desenhar();
        m.mostrar();
        previa();
    }
})();
