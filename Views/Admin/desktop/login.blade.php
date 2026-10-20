{{-- WebOS 登录窗口（2.1.77）：未登录访问 /admin/cmspro/webos 时由控制器渲染。 --}}
{{-- Windows 风格登录页为应用自有视图与样式（零 layui 依赖），认证仍走框架 /admin/login： --}}
{{-- CSRF/验证码/锁定/记住登录全套复用框架逻辑，redirect 参数登录成功后 302 直达桌面。 --}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 · WebOS 管理桌面</title>
    {{-- 壁纸/CMSPRO logo 地址经 asset() 生成后以 CSS 变量下发（login.css 内不硬编码路径，兼容子目录部署）：
         背景取 WebOS 默认壁纸（与桌面一致），头像位取框架登录页同源 CMSPRO logo --}}
    <style>:root{--webos-login-wallpaper:url("{{ asset('apps/cmspro.webos/images/webos-wallpaper.png') }}");--webos-login-avatar:url("{{ asset('Admin/images/logo.png') }}");}</style>
    <link rel="stylesheet" href="{{ asset('apps/cmspro.webos/css/login.css') }}?v={{ $webosAssetVersion }}">
</head>
<body>
    <main class="webos-login">
        <form class="webos-login-card" method="POST" action="{{ url('/admin/login') }}">
            @csrf
            <input type="hidden" name="redirect" value="/admin/cmspro/webos">
            <span class="webos-login-avatar" aria-hidden="true"></span>
            <h1 class="webos-login-title">CMSPRO <sup>v5</sup></h1>
            <p class="webos-login-desc">WebOS 管理桌面 · 请登录管理员账号</p>
            @if($errors->any())
            <p class="webos-login-error">{{ $errors->first() }}</p>
            @endif
            @if(session('error'))
            <p class="webos-login-error">{{ session('error') }}</p>
            @endif
            <label class="webos-login-field">
                <span class="webos-login-label">账号</span>
                <input name="username" type="text" placeholder="请输入账号" autocomplete="username" value="{{ old('username') }}" required>
            </label>
            <label class="webos-login-field">
                <span class="webos-login-label">密码</span>
                <input name="password" type="password" placeholder="请输入密码" autocomplete="current-password" required>
            </label>
            @if($captcha_enabled)
            <label class="webos-login-field webos-login-captcha">
                <span class="webos-login-label">验证码</span>
                <input name="captcha" type="text" placeholder="不区分大小写" autocomplete="off" required>
                <img id="webos-login-captcha-image" src="{{ $captcha_image }}" alt="登录验证码" title="点击刷新">
            </label>
            @endif
            <label class="webos-login-remember">
                <input type="checkbox" name="remember" value="1" checked>
                <span>记住登录状态</span>
            </label>
            <button class="webos-login-submit" type="submit">登 录</button>
        </form>
    </main>
    @if($captcha_enabled)
    <script>
    // 验证码点击刷新：复用框架公开接口（与传统登录页同源同逻辑）
    document.getElementById('webos-login-captcha-image').addEventListener('click', function () {
        var image = this;
        fetch('{{ url('/api/login-security/admin') }}').then(function (res) { return res.json(); }).then(function (res) {
            if (res.code === 0 && res.data && res.data.captcha_image) {
                image.src = res.data.captcha_image;
            }
        });
    });
    </script>
    @endif
</body>
</html>
