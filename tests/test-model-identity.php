<?php
use OpenTranslation\Model_Identity;

ot_test_group( 'Model_Identity：同一模型不同写法得到同一 key' );
$a = Model_Identity::key( array( 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => '' ) );
$b = Model_Identity::key( array( 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1/' ) );
$c = Model_Identity::key( array( 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => ' https://api.openai.com/v1 ' ) );
ot_assert_same( $a, $b, '留空与官方地址等价' );
ot_assert_same( $a, $c, '空白与尾斜杠被规范化' );
ot_assert_same( 32, strlen( $a ), 'md5 长度' );
ot_assert_true( $a !== Model_Identity::key( array( 'provider' => 'claude', 'model' => 'gpt-4o', 'base_url' => '' ) ), 'provider 不同 key 不同' );
ot_assert_true( $a !== Model_Identity::key( array( 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://gw.example.com/v1/' ) ), 'base_url 不同 key 不同' );
ot_assert_same( Model_Identity::key( array( 'model' => 'x' ) ), Model_Identity::key( array( 'provider' => 'openai', 'model' => 'x', 'base_url' => '' ) ), '缺 provider 与 base_url 时按默认值' );

ot_test_group( 'Model_Identity：label' );
ot_assert_same( 'openai / gpt-4o', Model_Identity::label( array( 'provider' => 'openai', 'model' => 'gpt-4o' ) ), '官方地址不显示主机' );
ot_assert_same( 'openai / gpt-4o @ gw.example.com', Model_Identity::label( array( 'provider' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://gw.example.com/v1/' ) ), '非官方地址显示主机名' );
ot_assert_same( 'claude / c1', Model_Identity::label( array( 'provider' => 'claude', 'model' => 'c1', 'base_url' => 'https://api.anthropic.com/v1/' ) ), 'Anthropic 官方地址不显示主机' );
