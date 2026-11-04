<?php
// Pengaturan Gateway WhatsApp — khusus admin.
// Panel + handler dipakai bersama dengan tab Gateway WA di Pengaturan;
// lihat pages/_gateway_panel.php dan includes/settings.php.
declare(strict_types=1);
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/Notify.php';
$u = require_role('admin');

wa_gateway_handle_post($u, '?page=gateway');
extract(wa_gateway_panel_data());

ob_start(); ?>
<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Gateway WhatsApp</h1>
  <p class="text-sm text-slate-500 mb-5">Pengaturan pengiriman WhatsApp untuk notifikasi Blanko (dokumen baru, hasil tinjauan, pengingat laporan). Semua nilai dinamis — disimpan di database dan langsung berlaku tanpa deploy ulang.</p>
  <?php require __DIR__ . '/_gateway_panel.php'; ?>
</div>
<?php layout('Gateway WhatsApp', ob_get_clean(), $u); ?>
