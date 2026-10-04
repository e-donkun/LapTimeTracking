<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Api.php';

Api::handle((string) ($_GET['r'] ?? ''));
