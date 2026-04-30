<?php

function conectarFirebird($empresa_id, $pdo_master) {

    $stmt = $pdo_master->prepare("
        SELECT firebird_path
        FROM empresas
        WHERE id = ?
    ");
    $stmt->execute([$empresa_id]);

    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        die("Empresa não encontrada");
    }

    try {
        $pdo_fb = new PDO(
            "firebird:dbname=localhost:" . $empresa['firebird_path'] . ";charset=UTF8",
            "SYSDBA",
            "masterkey"
        );

        return $pdo_fb;

    } catch (Exception $e) {
        die("Erro Firebird: " . $e->getMessage());
    }
}