import * as pdfjsLib from '../vendor/pdfjs/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
    '../vendor/pdfjs/pdf.worker.min.mjs',
    import.meta.url
).href;

const form = document.getElementById('form-importar-fatura');
const arquivo = document.getElementById('arquivo-fatura');
const senhaWrap = document.getElementById('senha-pdf-wrap');
const senha = document.getElementById('senha-pdf');
const payload = document.getElementById('pdf-payload');
const status = document.getElementById('pdf-status');
const botao = document.getElementById('btn-importar-fatura');
const competencia = document.getElementById('competencia-fatura');
const cartao = form?.querySelector('select[name="cartao_id"]');

function moeda(valor) {
    return Number(String(valor).replace(/\./g, '').replace(',', '.'));
}

const mapaFonteMercadoPago = new Map([
    ['A', 'fi'], ['ó', 'A'], ['x', 'ó'], ['.', 'x'], ['J', '.'], ['5', 'J'],
    ['%', '5'], ['(', '%'], ['7', '('], ['4', '7'], [')', '4'], ['I', ')'],
    ['F', 'I'], ['+', 'F'], ['8', '+'], ['C', '8'], ['í', 'C'], ['j', 'í'],
    ['R', 'j'], ['$', 'R'], ['9', '$'], ['M', '9'], ['ê', 'M'], ['b', 'ê'],
    ['D', 'b'], ['õ', 'D'], ['*', 'õ'], ['U', '*'], ['à', 'U'], ['z', 'à'],
    ['"', 'z'], ['”', '"'], ['ô', '”'], ['N', 'ô'], ['Q', 'N'], ['-', 'Q'],
    ['k', '-'],
]);

function decodificarFonteMercadoPago(texto) {
    return [...texto].map((caractere) => mapaFonteMercadoPago.get(caractere) ?? caractere).join('');
}

function normalizarItensMercadoPago(itens) {
    const marcador = itens.find((item) => /^\$9\s/.test(item.str.trim()))
        ?? itens.find((item) => /^íonsumos\b/i.test(item.str.trim()));
    if (!marcador?.fontName) return itens;

    return itens.map((item) => item.fontName === marcador.fontName
        ? { ...item, str: decodificarFonteMercadoPago(item.str) }
        : item);
}

function proximoValor(itens, rotulo) {
    const indice = itens.findIndex((item) => item.str.trim() === rotulo);
    if (indice < 0) return null;
    for (let i = indice + 1; i < Math.min(itens.length, indice + 8); i += 1) {
        const achou = itens[i].str.match(/R\$\s*([\d.]+,\d{2})/);
        if (achou) {
            const negativo = itens.slice(indice + 1, i + 1)
                .some((item) => item.str.trim() === '-' || /^-\s*R\$/.test(item.str.trim()));
            return moeda(achou[1]) * (negativo ? -1 : 1);
        }
    }
    return null;
}

function proximoValorPrefixo(itens, prefixo) {
    const item = itens.find((atual) => atual.str.trim().startsWith(prefixo));
    return item ? proximoValor(itens, item.str.trim()) : null;
}

function proximaData(itens, rotulo) {
    const indice = itens.findIndex((item) => item.str.trim() === rotulo);
    if (indice < 0) return '';
    for (let i = indice + 1; i < Math.min(itens.length, indice + 8); i += 1) {
        const achou = itens[i].str.match(/(\d{2})\/(\d{2})\/(\d{4})/);
        if (achou) return `${achou[3]}-${achou[2]}-${achou[1]}`;
    }
    return '';
}

function anoDaCompra(diaMes, vencimento) {
    const [, mes] = diaMes.split('/').map(Number);
    const [anoVenc, mesVenc] = vencimento.split('-').map(Number);
    return mes > mesVenc ? anoVenc - 1 : anoVenc;
}

function dataCompra(diaMes, vencimento) {
    const [dia, mes] = diaMes.split('/');
    return `${anoDaCompra(diaMes, vencimento)}-${mes}-${dia}`;
}

function linhasPorPosicao(itens) {
    const grupos = new Map();
    itens.filter((item) => item.str.trim()).forEach((item) => {
        const y = Math.round(item.transform[5]);
        if (!grupos.has(y)) grupos.set(y, []);
        grupos.get(y).push({ str: item.str.trim(), x: Math.round(item.transform[4]), y });
    });
    return [...grupos.values()].map((linha) => linha.sort((a, b) => a.x - b.x));
}

function transacoesPagina(itens, vencimento) {
    const marcadorCartao = itens.find((item) => /^Cart[aã]o\s+/i.test(item.str.trim()));
    if (!marcadorCartao) throw new Error('A secao de compras do cartao nao foi encontrada.');
    const yCartao = Math.round(marcadorCartao.transform[5]);
    const transacoes = [];

    linhasPorPosicao(itens).forEach((linha) => {
        const data = linha.find((item) => /^\d{2}\/\d{2}$/.test(item.str));
        const valor = linha.find((item) => /^R\$\s*[\d.]+,\d{2}$/.test(item.str));
        if (!data || !valor) return;

        const descricao = linha
            .filter((item) => item.x >= 80 && item.x < 390 && item !== valor)
            .map((item) => item.str)
            .join(' ')
            .trim();
        const parcelaItem = linha.find((item) => /^Parcela\s+\d+\s+de\s+\d+$/i.test(item.str));
        const ehCompra = data.y < yCartao;
        const ehPagamento = /pagamento da fatura/i.test(descricao);
        const ehCredito = /cr[eé]dito concedido|reembolso/i.test(descricao);
        const ehEncargo = /juros|iof|multa/i.test(descricao);
        let natureza = 'D';
        let categoria = ehCompra ? 'COMPRAS' : 'ENCARGOS';
        let tipo = parcelaItem ? parcelaItem.str.replace(/Parcela\s+(\d+)\s+de\s+(\d+)/i, 'Parcela $1/$2') : '';

        if (ehPagamento) {
            natureza = 'P';
            categoria = 'PAGAMENTO';
        } else if (ehCredito) {
            natureza = 'C';
            categoria = 'CREDITOS';
        } else if (!ehCompra && !ehEncargo) {
            return;
        }

        transacoes.push({
            data_compra: dataCompra(data.str, vencimento),
            descricao,
            categoria,
            tipo_lancamento: tipo,
            valor: moeda(valor.str.replace('R$', '').trim()),
            natureza,
        });
    });
    return transacoes;
}

async function hashArquivo(buffer) {
    const hash = await crypto.subtle.digest('SHA-256', buffer);
    return [...new Uint8Array(hash)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

export async function extrairMercadoPago(file, password) {
    const buffer = await file.arrayBuffer();
    const arquivoHash = await hashArquivo(buffer);
    const pdf = await pdfjsLib.getDocument({ data: new Uint8Array(buffer), password }).promise;
    if (pdf.numPages < 2) throw new Error('PDF Mercado Pago incompleto.');

    const pagina1Original = await (await pdf.getPage(1)).getTextContent();
    const pagina2Original = await (await pdf.getPage(2)).getTextContent();
    const pagina1 = { ...pagina1Original, items: normalizarItensMercadoPago(pagina1Original.items) };
    const pagina2 = { ...pagina2Original, items: normalizarItensMercadoPago(pagina2Original.items) };
    const texto1 = pagina1.items.map((item) => item.str).join(' ');
    if (!/Mercado Pago/i.test(texto1)) {
        throw new Error('O arquivo nao foi reconhecido como fatura Mercado Pago.');
    }

    const vencimento = proximaData(pagina1.items, 'Vence em');
    const total = proximoValor(pagina1.items, 'Total a pagar');
    const saldoAnterior = proximoValorPrefixo(pagina1.items, 'Total da fatura de ') ?? 0;
    const consumos = proximoValorPrefixo(pagina1.items, 'Consumos de ') ?? 0;
    const tarifas = proximoValor(pagina1.items, 'Tarifas e encargos') ?? 0;
    const multas = proximoValor(pagina1.items, 'Multas por atraso') ?? 0;
    const juros = proximoValor(pagina1.items, 'Juros do mes anterior') ?? proximoValor(pagina1.items, 'Juros do mês anterior') ?? 0;
    const creditos = proximoValor(pagina1.items, 'Pagamentos e creditos devolvidos') ?? proximoValor(pagina1.items, 'Pagamentos e créditos devolvidos') ?? 0;
    if (!vencimento || total === null) throw new Error('Vencimento ou total da fatura nao identificado.');

    const itens = transacoesPagina(pagina2.items, vencimento);
    const [ano, mes] = vencimento.split('-');
    const competenciaPdf = `${ano}-${mes}`;
    if (saldoAnterior !== 0) {
        itens.push({
            data_compra: `${competenciaPdf}-01`,
            descricao: saldoAnterior < 0 ? 'CREDITO DA FATURA ANTERIOR' : 'SALDO ANTERIOR DA FATURA',
            categoria: saldoAnterior < 0 ? 'CREDITOS' : 'SALDO ANTERIOR',
            tipo_lancamento: '',
            valor: Math.abs(saldoAnterior),
            natureza: saldoAnterior < 0 ? 'C' : 'S',
        });
    }

    const saldoAnteriorDebito = Math.max(saldoAnterior, 0);
    const creditoAnterior = Math.abs(Math.min(saldoAnterior, 0));

    return {
        origem: 'MERCADO_PAGO_PDF',
        hash_arquivo: arquivoHash,
        competencia: competenciaPdf,
        vencimento,
        total,
        resumo: {
            saldo_anterior: saldoAnteriorDebito,
            consumos,
            tarifas,
            multas,
            juros,
            creditos: creditos + creditoAnterior,
        },
        itens,
    };
}

if (arquivo && form) {
    arquivo.addEventListener('change', () => {
        const pdf = arquivo.files[0]?.name.toLowerCase().endsWith('.pdf');
        senhaWrap.classList.toggle('d-none', !pdf);
        senha.required = false;
        payload.value = '';
        status.textContent = pdf ? 'Informe a senha somente se o PDF estiver protegido.' : '';
    });

    form.addEventListener('submit', async (event) => {
        const file = arquivo.files[0];
        if (!file || !file.name.toLowerCase().endsWith('.pdf') || payload.value) return;
        event.preventDefault();
        botao.disabled = true;
        status.textContent = 'Lendo e validando a fatura Mercado Pago...';
        try {
            const dados = await extrairMercadoPago(file, senha.value);
            const mercadoPago = [...(cartao?.options ?? [])].find(
                (option) => option.textContent.trim().toUpperCase().startsWith('MERCADO PAGO')
            );
            if (!mercadoPago) {
                throw new Error('Cadastre e ative o cartao MERCADO PAGO antes de importar o PDF.');
            }
            cartao.value = mercadoPago.value;
            competencia.value = dados.competencia;
            payload.value = JSON.stringify(dados);
            status.textContent = `${dados.itens.length} lancamentos identificados. Enviando...`;
            form.submit();
        } catch (erro) {
            status.textContent = erro?.name === 'PasswordException'
                ? 'Senha incorreta ou PDF protegido nao reconhecido.'
                : (erro?.message || 'Nao foi possivel ler a fatura.');
            botao.disabled = false;
        }
    });
}
