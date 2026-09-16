<?php
use OpenTranslation\URL_Guard;

ot_test_group( 'URL_Guard：合法地址通过' );

ot_assert_same( true, URL_Guard::is_allowed( 'https://api.openai.com/v1/' ), '官方 OpenAI 地址通过' );
ot_assert_same( true, URL_Guard::is_allowed( 'https://api.anthropic.com/v1/' ), '官方 Claude 地址通过' );
ot_assert_same( true, URL_Guard::is_allowed( 'https://cpa.tandelin.cn/v1/' ), '自建网关（当前站点配置）通过' );

ot_test_group( 'URL_Guard：非 https 被拒' );

ot_assert_same( false, URL_Guard::is_allowed( 'http://api.openai.com/v1/' ), 'http 被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'ftp://example.com/' ), 'ftp 被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'file:///etc/passwd' ), 'file 协议被拒' );

ot_test_group( 'URL_Guard：内网与保留地址被拒' );

ot_assert_same( false, URL_Guard::is_allowed( 'https://127.0.0.1/v1/' ), '回环地址被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://localhost/v1/' ), 'localhost 被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://10.0.0.1/v1/' ), '10.x 私有段被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://172.16.0.1/v1/' ), '172.16.x 私有段被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://192.168.1.1/v1/' ), '192.168.x 私有段被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://169.254.169.254/latest/meta-data/' ), '云元数据地址被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://[::1]/v1/' ), 'IPv6 回环被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://gateway.internal/v1/' ), '.internal 后缀被拒' );

ot_test_group( 'URL_Guard：畸形输入被拒' );

ot_assert_same( false, URL_Guard::is_allowed( '' ), '空串被拒' );
ot_assert_same( false, URL_Guard::is_allowed( '/chat/completions' ), '相对路径被拒' );
ot_assert_same( false, URL_Guard::is_allowed( 'https://' ), '无主机名被拒' );

ot_test_group( 'URL_Guard：拒绝原因可读' );

$reason = URL_Guard::get_rejection_reason( 'http://127.0.0.1/v1/' );
ot_assert_true( is_string( $reason ) && '' !== $reason, '拒绝时返回非空原因' );
ot_assert_same( '', URL_Guard::get_rejection_reason( 'https://api.openai.com/v1/' ), '通过时返回空串' );

ot_test_group( 'URL_Guard：请求参数加固' );

$args = URL_Guard::harden_request_args( array( 'timeout' => 30 ) );
ot_assert_same( 0, $args['redirection'], '禁止跟随重定向' );
ot_assert_same( true, $args['reject_unsafe_urls'], '开启不安全 URL 拒绝' );
ot_assert_same( 30, $args['timeout'], '原有参数保留' );
