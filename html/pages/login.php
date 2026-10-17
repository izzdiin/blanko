<?php
// Login — modern mobile-first
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if ($u && password_verify($pass, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        redirect('?page=dashboard');
    }
    flash('Email atau password salah.', 'err');
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
  <div class="mt-4 text-xs text-slate-500 bg-white rounded-2xl shadow-sm p-4">
    <div class="font-semibold text-slate-700 mb-1">Akun demo</div>
    <div>Mitra: mitra@blanko.demo / mitra123</div>
    <div>Penerjemah: tersumpah@blanko.demo / tersumpah123</div>
  </div>
</div>
<?php layout('Masuk', ob_get_clean()); ?>
