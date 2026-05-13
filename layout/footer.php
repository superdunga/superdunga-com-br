</main>

<?php
$scriptDirFooter = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$modulosPosFooter = strpos($scriptDirFooter, '/modulos');
$appBaseUrlFooter = $modulosPosFooter !== false ? substr($scriptDirFooter, 0, $modulosPosFooter) : $scriptDirFooter;
$appBaseUrlFooter = rtrim($appBaseUrlFooter, '/');
$bootstrapJsUrl = ($appBaseUrlFooter ?: '') . '/assets/bootstrap/bootstrap.bundle.min.js';
?>

<!-- Bootstrap JS -->
<script src="<?= htmlspecialchars($bootstrapJsUrl) ?>"></script>

<footer class="text-center py-4 text-muted">
    <small>SuperDunga &copy; <?= date('Y') ?> - Sistema Financeiro</small>
</footer>

</body>
</html>
