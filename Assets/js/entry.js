/**
 * WebOS 管理桌面：传统后台右下角「进入 WebOS」卷页（翻书页）入口。
 *
 * 视觉还原真实纸张翻卷：入口是一张贴在右下角的白纸，其右下角沿
 * 「右上→左下」对角折痕翻起（露出浅灰的纸背面），悬停时纸张被
 * 动态拉开——白纸沿对角线向左上收拢、纸背面翻走，露出深蓝底的
 * 「进入 WebOS」内容页，点击进入 WebOS 桌面。
 *
 * 由 ServiceProvider 通过系统布局 @stack('page_scripts') 注入；
 * 页面中已输出载体节点 #webos-float-book-carrier（携带 data-url 跳转地址）。
 * 关键样式全部以内联 style 写入（内联优先级最高，不受后台主题样式覆盖），
 * 交互由 mouseenter/mouseleave 事件驱动，不依赖 CSS 伪类。
 */
(function () {
    'use strict';

    var carrier = document.getElementById('webos-float-book-carrier');
    if (!carrier) {
        return;
    }

    var entryUrl = carrier.getAttribute('data-url');
    if (!entryUrl) {
        return;
    }

    // 静态与悬停两种状态下的纸张裁剪形状（同顶点数，支持 clip-path 过渡动画）
    var FRONT_SHAPE_IDLE = 'polygon(0 0, 100% 0, 0 100%)';          // 白纸正面：左上大三角
    var FRONT_SHAPE_OPEN = 'polygon(0 0, 14% 0, 0 14%)';            // 拉开后：白纸收拢到左上角
    var BACK_SHAPE_IDLE = 'polygon(100% 0, 100% 100%, 0 100%)';     // 纸背面：右下翻起三角

    // 容器：静态只占右下角折角区域，悬停时展开为完整入口
    var book = document.createElement('a');
    book.id = 'webos-float-book';
    book.href = entryUrl;
    book.title = '进入 WebOS 管理桌面';
    book.style.cssText =
        'position:fixed;right:0;bottom:0;z-index:9998;width:64px;height:64px;' +
        'display:block;text-decoration:none;transition:width 0.35s ease,height 0.35s ease;';

    // 内容页（最底层）：深蓝渐变底、白字，白纸拉开后完整露出
    var content = document.createElement('span');
    content.style.cssText =
        'position:absolute;right:0;bottom:0;width:100%;height:100%;z-index:1;' +
        'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;' +
        'border-top-left-radius:16px;background:linear-gradient(135deg,#2b7fe0,#12325e);' +
        'color:#fff;font-size:13px;font-weight:600;letter-spacing:1px;line-height:1;' +
        'box-shadow:-4px -4px 14px rgba(20,60,120,0.35);overflow:hidden;';

    var icon = document.createElement('i');
    icon.className = 'layui-icon layui-icon-console';
    icon.style.cssText = 'font-size:22px;line-height:1;color:#fff;';

    var label = document.createElement('span');
    label.textContent = '进入 WebOS';
    label.style.cssText = 'color:#fff;font-size:12px;font-weight:600;';

    content.appendChild(icon);
    content.appendChild(label);

    // 纸背面（中层）：右下翻起的三角，折痕处暗带模拟卷筒投影，向右下渐白为受光纸面
    var pageBack = document.createElement('span');
    pageBack.style.cssText =
        'position:absolute;right:0;bottom:0;width:100%;height:100%;z-index:2;' +
        'clip-path:' + BACK_SHAPE_IDLE + ';' +
        'background:linear-gradient(135deg,rgba(0,0,0,0) 47%,#96a1ad 50%,#e6ebf0 58%,#fafbfc 80%,#ffffff 100%);' +
        'transform-origin:bottom right;transition:clip-path 0.35s ease,opacity 0.35s ease,transform 0.35s ease;';

    // 白纸正面（顶层）：左上三角，靠折痕处轻微渐暗增强立体感
    var paperFront = document.createElement('span');
    paperFront.style.cssText =
        'position:absolute;right:0;bottom:0;width:100%;height:100%;z-index:3;' +
        'clip-path:' + FRONT_SHAPE_IDLE + ';' +
        'background:linear-gradient(135deg,#ffffff 0%,#f7f9fb 60%,#eef1f5 100%);' +
        'transition:clip-path 0.35s ease;';

    book.appendChild(content);
    book.appendChild(pageBack);
    book.appendChild(paperFront);
    document.body.appendChild(book);

    // 悬停：纸张沿对角折痕动态拉开——白纸收拢到左上角、纸背面翻走，内容页显现
    book.addEventListener('mouseenter', function () {
        book.style.width = '120px';
        book.style.height = '120px';
        paperFront.style.clipPath = FRONT_SHAPE_OPEN;
        pageBack.style.opacity = '0';
        pageBack.style.transform = 'translate(16px,16px) scale(0.25)';
    });
    book.addEventListener('mouseleave', function () {
        book.style.width = '64px';
        book.style.height = '64px';
        paperFront.style.clipPath = FRONT_SHAPE_IDLE;
        pageBack.style.opacity = '1';
        pageBack.style.transform = 'scale(1)';
    });

    // 升级浮动按钮（右下角同区域、z-index 更高）出现时入口上移避让，关闭后复位贴角
    var upgradeButton = document.getElementById('upgradeFloatBtn');
    if (upgradeButton && typeof MutationObserver !== 'undefined') {
        var syncPosition = function () {
            book.style.bottom = upgradeButton.offsetWidth > 0 ? '80px' : '0px';
        };
        new MutationObserver(syncPosition).observe(upgradeButton, {
            attributes: true,
            attributeFilter: ['style']
        });
        syncPosition();
    }
})();
