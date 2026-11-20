<?php
// Cek waktu server (file sementara — hapus setelah dicek).
echo 'Waktu PHP: ' . date('Y-m-d H:i:s') . '<br>';
echo 'Timezone PHP: ' . date_default_timezone_get() . '<br>';
echo 'Waktu UTC: ' . gmdate('Y-m-d H:i:s') . '<br>';
