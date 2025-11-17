<?php
/**
 * Language Detection & Loading System
 * Automatically detects and loads the appropriate language file
 * Compatible with MySQLi
 */

// Detect user's preferred language
function detect_language() {
    // Priority 1: Session language (user actively selected)
    if (isset($_SESSION['language'])) {
        return $_SESSION['language'];
    }
    
    // Priority 2: Database preference (for logged-in users)
    if (isset($_SESSION['user_id']) && isset($GLOBALS['conn'])) {
        try {
            $user_id = $_SESSION['user_id'];
            $stmt = $GLOBALS['conn']->prepare("SELECT language_preference FROM users WHERE id = ?");
            
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                $stmt->close();
                
                if ($user && !empty($user['language_preference'])) {
                    $_SESSION['language'] = $user['language_preference'];
                    return $user['language_preference'];
                }
            }
        } catch (Exception $e) {
            error_log("Language detection error: " . $e->getMessage());
        }
    }
    
    // Priority 3: Cookie (for non-logged-in users)
    if (isset($_COOKIE['language'])) {
        $_SESSION['language'] = $_COOKIE['language'];
        return $_COOKIE['language'];
    }
    
    // Priority 4: Browser language
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        $supported = ['en', 'tl', 'es', 'zh', 'ja'];
        
        if (in_array($browser_lang, $supported)) {
            $_SESSION['language'] = $browser_lang;
            return $browser_lang;
        }
    }
    
    // Default: English
    $_SESSION['language'] = 'en';
    return 'en';
}

// Load language file
function load_language($lang = null) {
    if ($lang === null) {
        $lang = detect_language();
    }
    
    $lang_file = __DIR__ . '/../lang/' . $lang . '.php';
    
    // Check if language file exists
    if (file_exists($lang_file)) {
        return include($lang_file);
    }
    
    // Fallback to English
    $fallback = __DIR__ . '/../lang/en.php';
    if (file_exists($fallback)) {
        return include($fallback);
    }
    
    // Emergency fallback - return empty array
    return [];
}

// Initialize language system only if not already initialized
if (!isset($GLOBALS['translations'])) {
    $GLOBALS['current_language'] = detect_language();
    $GLOBALS['translations'] = load_language($GLOBALS['current_language']);
}

// Translation helper function
function t($key, $params = []) {
    $translations = $GLOBALS['translations'] ?? [];
    
    // Navigate through nested keys (e.g., "dashboard.welcome")
    $keys = explode('.', $key);
    $value = $translations;
    
    foreach ($keys as $k) {
        if (isset($value[$k])) {
            $value = $value[$k];
        } else {
            // Return key if translation not found
            return $key;
        }
    }
    
    // Replace parameters in translation string
    if (!empty($params) && is_string($value)) {
        foreach ($params as $param_key => $param_value) {
            $value = str_replace(':' . $param_key, $param_value, $value);
        }
    }
    
    return $value;
}

// Get current language code
function current_lang() {
    return $GLOBALS['current_language'] ?? 'en';
}

// Get language name
function get_language_name($code = null) {
    if ($code === null) {
        $code = current_lang();
    }
    
    $languages = [
        'en' => 'English',
        'tl' => 'Tagalog',
        'es' => 'Español',
        'zh' => '中文',
        'ja' => '日本語'
    ];
    
    return $languages[$code] ?? 'English';
}

// Get all supported languages
function get_supported_languages() {
    return [
        'en' => 'English',
        'tl' => 'Tagalog',
        'es' => 'Español',
        'zh' => '中文',
        'ja' => '日本語'
    ];
}

// Clear translation cache
function clear_user_translation_cache() {
    $cache_dir = __DIR__ . '/../cache/translations/';
    if (isset($_SESSION['user_id'])) {
        $cache_file = $cache_dir . 'user_' . $_SESSION['user_id'] . '.cache';
        if (file_exists($cache_file)) {
            unlink($cache_file);
        }
    }
}

// Language switcher widget generator
function render_language_switcher($current_page = null) {
    if ($current_page === null) {
        $current_page = $_SERVER['PHP_SELF'];
    }
    
    $current = current_lang();
    $languages = get_supported_languages();
    
    $html = '<div class="language-switcher">';
    $html .= '<button class="lang-toggle" id="langToggle" type="button">';
    
    // Check if flag exists, otherwise use text
    $flag_path = __DIR__ . '/../assets/img/icons/flags/' . $current . '.svg';
    if (file_exists($flag_path)) {
        $html .= '<img src="assets/img/icons/flags/' . $current . '.svg" alt="' . $languages[$current] . '" class="flag-icon">';
    } else {
        $html .= '<span class="flag-icon flag-text">' . strtoupper($current) . '</span>';
    }
    
    $html .= '<span class="lang-name">' . $languages[$current] . '</span>';
    $html .= '<svg class="dropdown-arrow" width="12" height="12" viewBox="0 0 12 12"><path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="2" fill="none"/></svg>';
    $html .= '</button>';
    $html .= '<div class="lang-dropdown" id="langDropdown">';
    
    foreach ($languages as $code => $name) {
        $active = ($code === $current) ? ' active' : '';
        $html .= '<a href="change_language.php?lang=' . $code . '&redirect=' . urlencode($current_page) . '" class="lang-option' . $active . '">';
        
        // Check if flag exists
        $flag_path = __DIR__ . '/../assets/img/icons/flags/' . $code . '.svg';
        if (file_exists($flag_path)) {
            $html .= '<img src="assets/img/icons/flags/' . $code . '.svg" alt="' . $name . '" class="flag-icon">';
        } else {
            $html .= '<span class="flag-icon flag-text">' . strtoupper($code) . '</span>';
        }
        
        $html .= '<span>' . $name . '</span>';
        if ($code === $current) {
            $html .= '<svg class="check-icon" width="16" height="16" viewBox="0 0 16 16"><path d="M13 4L6 11 3 8" stroke="currentColor" stroke-width="2" fill="none"/></svg>';
        }
        $html .= '</a>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}
?>