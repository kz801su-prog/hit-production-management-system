</div><!-- /container-fluid -->

<footer class="footer mt-auto py-2 bg-dark text-white-50 text-center small">
  <?= h(APP_NAME) ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?> オーツーファーニチャー
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<?php if (!empty($extraJs)): ?>
<script><?= $extraJs ?></script>
<?php endif; ?>
</body>
</html>
