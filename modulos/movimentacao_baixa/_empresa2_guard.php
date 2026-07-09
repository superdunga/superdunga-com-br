<?php
if ((int)($_SESSION['empresa_id'] ?? 0) !== 2) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Modulo exclusivo</title>
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                font-family: Arial, sans-serif;
                background: #f3f6fb;
                color: #1f2937;
            }
            .box {
                width: min(520px, calc(100% - 32px));
                padding: 24px;
                border-radius: 8px;
                background: #fff;
                box-shadow: 0 12px 30px rgba(15, 23, 42, .12);
            }
            h1 {
                margin: 0 0 10px;
                font-size: 22px;
            }
            p {
                margin: 0 0 18px;
                line-height: 1.45;
            }
            a {
                display: inline-block;
                padding: 10px 14px;
                border-radius: 6px;
                background: #153a78;
                color: #fff;
                text-decoration: none;
                font-weight: 700;
            }
        </style>
    </head>
    <body>
        <main class="box">
            <h1>Modulo exclusivo da empresa 2</h1>
            <p>Para empresas integradas ao Firebird, utilize as telas do modulo Financeiro.</p>
            <a href="../../index.php">Voltar ao inicio</a>
        </main>
    </body>
    </html>
    <?php
    exit;
}
