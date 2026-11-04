<?php
// Login — modern mobile-first.
// Perlindungan brute-force: lockout bertahap per email + per IP,
// lihat includes/ratelimit.php.
declare(strict_types=1);
require_once __DIR__ . '/../includes/ratelimit.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $ip = rl_client_ip();
    $locked = rl_login_locked_seconds($email, $ip);
    if ($locked > 0) {
        flash(sprintf(
            'Terlalu banyak percobaan masuk yang gagal. Coba lagi dalam %d menit.',
            (int)ceil($locked / 60)
        ), 'err');
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['password_hash'])) {
            rl_login_clear_email($email);
            $st = $u['status'] ?? 'active';
            if ($st === 'rejected') {
                flash('Akun ditolak. Hubungi admin.', 'err');
            } else {
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                redirect($st === 'pending' ? '?page=pending' : '?page=dashboard');
            }
        } else {
            rl_login_record_failure($email, $ip);
            flash('Email atau password salah.', 'err');
        }
    }
}
ob_start(); ?>
<div class="max-w-md mx-auto pt-8 sm:pt-14">
  <div class="text-center mb-8">
    <div class="w-16 h-16 rounded-3xl bg-indigo-600 text-white grid place-items-center mx-auto mb-4 shadow-lg shadow-indigo-600/30">
      <i class="fa-solid fa-stamp text-2xl"></i>
    </div>
    <h1 class="text-2xl font-bold tracking-tight">Selamat datang</h1>
    <p class="text-sm text-slate-500 mt-1">Masuk untuk mengelola dokumen terjemahan</p>
  </div>
  <form method="post" class="bg-white rounded-3xl shadow-sm p-6 space-y-4">
    <?= csrf_field() ?>
    <div>
      <label class="block text-sm font-medium mb-1.5">Email</label>
      <input type="email" name="email" required autocomplete="username"
             class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="nama@email.com">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1.5">Password</label>
      <input type="password" name="password" required autocomplete="current-password"
             class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="••••••••">
    </div>
    <button class="w-full h-12 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25 active:scale-[.98] transition">
      Masuk
    </button>
  </form>
  <p class="text-center text-sm text-slate-500 mt-4">Belum punya akun? <a href="?page=register" class="text-indigo-600 font-semibold hover:underline">Daftar di sini</a></p>
  <div class="mt-4 text-xs text-slate-500 bg-white rounded-2xl shadow-sm p-4">
    <div class="font-semibold text-slate-700 mb-1">Akun demo</div>
    <div>Mitra: mitra@blanko.demo / mitra123</div>
    <div>Penerjemah: tersumpah@blanko.demo / tersumpah123</div>
  </div>
</div>
<?php layout('Masuk', ob_get_clean()); ?>
