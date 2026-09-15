<?php
use OpenTranslation\Translator;

/**
 * 直通判定：这些内容不该送给模型翻译。
 * 覆盖合约验收标准 1 的最后一条（URL、邮箱、媒体路径、语言码各至少 1 例）。
 */

ot_test_group( '直通判定：空值' );

ot_assert_same( '', Translator::get_passthrough_translation( '' ), '空串直通为空串' );
ot_assert_same( '', Translator::get_passthrough_translation( '   ' ), '纯空白直通为空串' );

ot_test_group( '直通判定：URL' );

ot_assert_same(
    'https://example.com/page',
    Translator::get_passthrough_translation( 'https://example.com/page' ),
    'https URL 原样直通'
);
ot_assert_same(
    'http://example.com/a/b?c=1',
    Translator::get_passthrough_translation( 'http://example.com/a/b?c=1' ),
    '带查询串的 URL 原样直通'
);

ot_test_group( '直通判定：邮箱与协议前缀' );

ot_assert_same(
    'sales@example.com',
    Translator::get_passthrough_translation( 'sales@example.com' ),
    '邮箱原样直通'
);
ot_assert_same(
    'mailto:sales@example.com',
    Translator::get_passthrough_translation( 'mailto:sales@example.com' ),
    'mailto: 原样直通'
);
ot_assert_same(
    'tel:+8613800138000',
    Translator::get_passthrough_translation( 'tel:+8613800138000' ),
    'tel: 原样直通'
);

ot_test_group( '直通判定：语言码' );

ot_assert_same( 'zh_CN', Translator::get_passthrough_translation( 'zh_CN' ), '下划线语言码原样直通' );
ot_assert_same( 'en-US', Translator::get_passthrough_translation( 'en-US' ), '连字符语言码原样直通' );
ot_assert_same( 'pt-br', Translator::get_passthrough_translation( 'pt-br' ), '小写语言码原样直通' );

ot_test_group( '直通判定：媒体路径' );

ot_assert_same(
    '/wp-content/uploads/2026/01/photo.jpg',
    Translator::get_passthrough_translation( '/wp-content/uploads/2026/01/photo.jpg' ),
    '相对图片路径原样直通'
);
ot_assert_same(
    '/assets/demo.mp4',
    Translator::get_passthrough_translation( '/assets/demo.mp4' ),
    '相对视频路径原样直通'
);
ot_assert_same(
    'banner.png?ver=1.2',
    Translator::get_passthrough_translation( 'banner.png?ver=1.2' ),
    '带版本号的图片名原样直通'
);

ot_test_group( '直通判定：正常文本必须交给模型' );

ot_assert_same( null, Translator::get_passthrough_translation( 'Buy now' ), '普通短语不直通' );
ot_assert_same(
    null,
    Translator::get_passthrough_translation( 'Water flow sensor WFS-B11A-GD-FM' ),
    '含型号的产品名不直通'
);
ot_assert_same( null, Translator::get_passthrough_translation( '<b>Hello</b>' ), '含 HTML 的文本不直通' );
ot_assert_same( null, Translator::get_passthrough_translation( 'Home' ), '单个单词不被误判为语言码' );
ot_assert_same( null, Translator::get_passthrough_translation( 'News&amp;Blog' ), '含实体的文本不直通' );
