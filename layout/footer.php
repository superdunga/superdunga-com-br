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

<script>
(function () {
    function gerarTokenFormulario() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return String(Date.now()) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
    }

    function garantirToken(form) {
        if (!form || String(form.method || 'get').toLowerCase() !== 'post') {
            return;
        }

        var token = form.querySelector('input[name="_sd_submit_token"]');
        if (!token) {
            token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_sd_submit_token';
            token.value = gerarTokenFormulario();
            form.appendChild(token);
        }
    }

    document.querySelectorAll('form').forEach(garantirToken);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || String(form.method || 'get').toLowerCase() !== 'post') {
            return;
        }

        garantirToken(form);

        if (form.dataset.sdSubmitting === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        if (event.defaultPrevented) {
            return;
        }

        form.dataset.sdSubmitting = '1';
        form.setAttribute('aria-busy', 'true');

        var submitter = event.submitter;
        if (submitter) {
            submitter.setAttribute('aria-disabled', 'true');
            submitter.style.pointerEvents = 'none';
            submitter.classList.add('disabled');
            if (!submitter.dataset.sdTextoOriginal) {
                submitter.dataset.sdTextoOriginal = submitter.textContent;
            }
            submitter.textContent = 'Processando...';
        }
    });
}());
</script>

<footer class="text-center py-4 text-muted">
    <small>SuperDunga &copy; <?= date('Y') ?> - Sistema Financeiro</small>
</footer>

</body>
</html>
