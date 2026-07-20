<style>
.mb-select-search {
    position: relative;
    width: 100%;
}
.mb-select-search select.mb-select-search-native {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    opacity: 0 !important;
    pointer-events: none !important;
}
.mb-select-search-control {
    position: relative;
}
.mb-select-search-input {
    width: 100%;
    min-height: 38px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #fff;
    color: #0f172a;
    font: inherit;
    padding: 8px 34px 8px 10px;
}
.mb-select-search-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    outline: 0;
}
.mb-select-search-input[disabled] {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
}
.mb-select-search-arrow {
    position: absolute;
    right: 1px;
    top: 1px;
    bottom: 1px;
    width: 34px;
    border: 0;
    border-left: 1px solid #e2e8f0;
    border-radius: 0 6px 6px 0;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    font-size: 0.8rem;
}
.mb-select-search-arrow:focus {
    outline: 0;
}
.mb-select-search-arrow:disabled {
    cursor: not-allowed;
}
.mb-select-search-list {
    position: absolute;
    z-index: 4000;
    left: 0;
    right: 0;
    top: calc(100% + 4px);
    display: none;
    max-height: 260px;
    overflow-y: auto;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #fff;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.16);
}
.mb-select-search.open .mb-select-search-list {
    display: block;
}
.mb-select-search-option {
    padding: 8px 10px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    font-size: 0.92rem;
    color: #0f172a;
}
.mb-select-search-option:last-child {
    border-bottom: 0;
}
.mb-select-search-option:hover,
.mb-select-search-option.active {
    background: #e0f2fe;
}
.mb-select-search-option.empty {
    color: #64748b;
    cursor: default;
}
</style>

<script>
(function () {
    function normalizar(valor) {
        return (valor || '')
            .toString()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function textoOpcao(option) {
        return (option.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function opcoesDisponiveis(select) {
        return Array.from(select.options).filter(function (option) {
            return !option.hidden && !option.disabled;
        });
    }

    function criarBusca(select) {
        if (select.dataset.mbSelectSearchReady === '1' || select.multiple) {
            return;
        }

        select.dataset.mbSelectSearchReady = '1';
        const obrigatorio = select.required;
        if (obrigatorio) {
            select.required = false;
            select.dataset.mbRequired = '1';
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'mb-select-search';
        const parent = select.parentNode;
        parent.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('mb-select-search-native');

        const control = document.createElement('div');
        control.className = 'mb-select-search-control';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'mb-select-search-input';
        input.autocomplete = 'off';
        input.placeholder = 'Digite para buscar...';
        input.disabled = select.disabled;
        const arrow = document.createElement('button');
        arrow.type = 'button';
        arrow.className = 'mb-select-search-arrow';
        arrow.textContent = 'v';
        arrow.disabled = select.disabled;
        const list = document.createElement('div');
        list.className = 'mb-select-search-list';
        control.appendChild(input);
        control.appendChild(arrow);
        wrapper.appendChild(control);
        wrapper.appendChild(list);

        let indiceAtivo = -1;

        function optionSelecionada() {
            return select.options[select.selectedIndex] || null;
        }

        function sincronizarTexto() {
            const option = optionSelecionada();
            input.value = option && option.value !== '' ? textoOpcao(option) : '';
        }

        function fechar() {
            wrapper.classList.remove('open');
            indiceAtivo = -1;
        }

        function selecionar(option) {
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            sincronizarTexto();
            fechar();
        }

        function renderizar(filtro) {
            const filtroNormalizado = normalizar(filtro);
            const termos = filtroNormalizado ? filtroNormalizado.split(/\s+/).filter(Boolean) : [];
            const opcoes = opcoesDisponiveis(select).filter(function (option) {
                if (!termos.length) {
                    return true;
                }
                const texto = normalizar(textoOpcao(option) + ' ' + option.value);
                return termos.every(function (termo) {
                    return texto.indexOf(termo) !== -1;
                });
            });

            list.innerHTML = '';
            if (!opcoes.length) {
                const vazio = document.createElement('div');
                vazio.className = 'mb-select-search-option empty';
                vazio.textContent = 'Nenhum resultado encontrado';
                list.appendChild(vazio);
                return;
            }

            opcoes.forEach(function (option, index) {
                const item = document.createElement('div');
                item.className = 'mb-select-search-option';
                if (option.value === select.value) {
                    item.classList.add('active');
                    indiceAtivo = index;
                }
                item.dataset.value = option.value;
                item.textContent = textoOpcao(option);
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    selecionar(option);
                });
                list.appendChild(item);
            });
        }

        function abrir(filtro) {
            if (select.disabled) {
                return;
            }
            renderizar(filtro !== undefined ? filtro : input.value);
            wrapper.classList.add('open');
        }

        function itensRenderizados() {
            return Array.from(list.querySelectorAll('.mb-select-search-option:not(.empty)'));
        }

        function atualizarAtivo(itens) {
            itens.forEach(function (item, index) {
                item.classList.toggle('active', index === indiceAtivo);
            });
            if (itens[indiceAtivo]) {
                itens[indiceAtivo].scrollIntoView({ block: 'nearest' });
            }
        }

        input.addEventListener('focus', abrir);
        input.addEventListener('click', abrir);
        arrow.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        arrow.addEventListener('click', function () {
            if (select.disabled) {
                return;
            }
            input.focus();
            abrir('');
        });
        input.addEventListener('input', function () {
            wrapper.classList.add('open');
            indiceAtivo = -1;
            renderizar(input.value);
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                sincronizarTexto();
                fechar();
            }, 160);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                abrir();
                const itens = itensRenderizados();
                indiceAtivo = Math.min(indiceAtivo + 1, itens.length - 1);
                atualizarAtivo(itens);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (!wrapper.classList.contains('open')) {
                    abrir();
                }
                const itens = itensRenderizados();
                indiceAtivo = Math.max(indiceAtivo - 1, 0);
                atualizarAtivo(itens);
            } else if (event.key === 'Enter') {
                const itens = itensRenderizados();
                if (wrapper.classList.contains('open') && itens[indiceAtivo]) {
                    event.preventDefault();
                    const option = opcoesDisponiveis(select).find(function (opt) {
                        return opt.value === itens[indiceAtivo].dataset.value;
                    });
                    if (option) {
                        selecionar(option);
                    }
                }
                return;
            } else if (event.key === 'Escape') {
                fechar();
                return;
            } else {
                return;
            }
        });

        select.addEventListener('change', sincronizarTexto);
        select.addEventListener('disabledchange', function () {
            input.disabled = select.disabled;
            arrow.disabled = select.disabled;
        });
        sincronizarTexto();

        const form = select.closest('form');
        if (form && obrigatorio && !form.dataset.mbSelectSearchValidated) {
            form.dataset.mbSelectSearchValidated = '1';
            form.addEventListener('submit', function (event) {
                const invalido = Array.from(form.querySelectorAll('select[data-mb-required="1"]')).find(function (campo) {
                    return !campo.value;
                });
                if (invalido) {
                    event.preventDefault();
                    const busca = invalido.closest('.mb-select-search')?.querySelector('.mb-select-search-input');
                    if (busca) {
                        busca.focus();
                    }
                    alert('Preencha os campos obrigatorios de selecao.');
                }
            });
        }
    }

    function iniciarBuscas() {
        document.querySelectorAll('select:not([data-mb-select-search=\"off\"])').forEach(criarBusca);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarBuscas);
    } else {
        iniciarBuscas();
    }
})();
</script>
