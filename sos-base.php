<?php
/**
 * AI Community SOS Provider Base Class
 * 
 * @package AI_Community
 * 
 * NOT: Bu dosya sadece geriye uyumluluk için tutulmaktadır.
 * AI_Community_SOS_Provider class'ı provider-base.php içinde tanımlanmıştır.
 */

if (!defined('ABSPATH')) exit;

// Eğer class henüz tanımlanmamışsa, provider-base.php'den yükle
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/provider-base.php';
}

// SOS Provider için ek yardımcı fonksiyonlar veya trait'ler buraya eklenebilir
// Ancak class tanımı provider-base.php'de yapıldığı için burada tekrar tanımlama YOK

/**
 * SOS Provider Yardımcı Fonksiyonları (Opsiyonel)
 * Bu fonksiyonlar class dışında kullanılabilir yardımcı araçlardır
 */
if (!function_exists('ai_community_format_phone_for_sos')) {
    function ai_community_format_phone_for_sos($phone) {
        // Sadece rakamlar ve + işareti kalacak
        return preg_replace('/[^0-9+]/', '', $phone);
    }
}

if (!function_exists('ai_community_generate_sos_code')) {
    function ai_community_generate_sos_code($length = 6) {
        return str_pad(wp_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}