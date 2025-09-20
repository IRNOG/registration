<?php

// بارگذاری تنظیمات از فایل .env
if (file_exists('.env')) {
    include '.env'; 
}

define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('CHANNEL_ID', getenv('CHANNEL_ID'));
define('REVIEW_GROUP_ID', getenv('REVIEW_GROUP_ID')); // تغییر از ADMIN_ID به REVIEW_GROUP_ID
define('DB_PATH', getenv('DB_PATH') ?: 'bot_database.sqlite');
define('GROUP_LINK', getenv('GROUP_LINK'));

// اتصال به دیتابیس و ایجاد جداول
function initDatabase() {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ایجاد جدول کاربران
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            chat_id INTEGER PRIMARY KEY,
            state TEXT,
            name TEXT,
            company TEXT,
            expertise TEXT,
            email TEXT,
            motivation TEXT,
            verification_type TEXT,
            verification_value TEXT,
            verification_ref_name TEXT,
            status TEXT DEFAULT 'در انتظار بررسی',
            rejection_reason TEXT,
            reviewed_by_user_id TEXT,
            reviewed_by_username TEXT,
            review_decision TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    return $pdo;
}

// دریافت اتصال دیتابیس
function getDatabase() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = initDatabase();
    }
    return $pdo;
}

// توابع کار با کاربران
function getUserState($chat_id) {
    $pdo = getDatabase();
    $stmt = $pdo->prepare("SELECT state FROM users WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['state'] : null;
}

function setUserState($chat_id, $state) {
    $pdo = getDatabase();
    $stmt = $pdo->prepare("
        INSERT OR REPLACE INTO users (chat_id, state, updated_at) 
        VALUES (?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$chat_id, $state]);
}

function saveUserData($chat_id, $field, $value) {
    $pdo = getDatabase();
    
    // بررسی وجود کاربر
    $stmt = $pdo->prepare("SELECT chat_id FROM users WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    
    if (!$stmt->fetch()) {
        // ایجاد کاربر جدید
        $stmt = $pdo->prepare("INSERT INTO users (chat_id) VALUES (?)");
        $stmt->execute([$chat_id]);
    }
    
    // بروزرسانی فیلد مورد نظر
    $stmt = $pdo->prepare("UPDATE users SET $field = ?, updated_at = CURRENT_TIMESTAMP WHERE chat_id = ?");
    $stmt->execute([$value, $chat_id]);
}

function getUserData($chat_id, $field = null) {
    $pdo = getDatabase();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return null;
    }
    
    if ($field === null) {
        return $user;
    }
    
    return $user[$field] ?? null;
}

function saveVerificationData($chat_id, $type, $value, $ref_name = null) {
    $pdo = getDatabase();
    $stmt = $pdo->prepare("
        UPDATE users 
        SET verification_type = ?, verification_value = ?, verification_ref_name = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE chat_id = ?
    ");
    $stmt->execute([$type, $value, $ref_name, $chat_id]);
}

// توابع ارتباط با API تلگرام
function makeHTTPRequest($method, $params = []) {
    $url = API_URL . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

function sendMessage($chat_id, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    
    return makeHTTPRequest('sendMessage', $params);
}

function editMessageText($chat_id, $message_id, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    
    return makeHTTPRequest('editMessageText', $params);
}

function checkChannelMembership($chat_id, $user_id) {
    $result = makeHTTPRequest('getChatMember', [
        'chat_id' => CHANNEL_ID,
        'user_id' => $user_id
    ]);
    
    return isset($result['result']['status']) && 
           in_array($result['result']['status'], ['member', 'administrator', 'creator']);
}

// بررسی عضویت در گروه بررسی
function checkReviewGroupMembership($user_id) {
    $result = makeHTTPRequest('getChatMember', [
        'chat_id' => REVIEW_GROUP_ID,
        'user_id' => $user_id
    ]);
    
    return isset($result['result']['status']) && 
           in_array($result['result']['status'], ['member', 'administrator', 'creator']);
}

function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function isValidLinkedInUrl($url) {
    return isValidUrl($url) && strpos($url, 'linkedin.com') !== false;
}

function showUserProfile($chat_id) {
    $userData = getUserData($chat_id);
    
    if (!$userData) {
        sendMessage($chat_id, "اطلاعاتی برای شما ثبت نشده است. لطفاً با استفاده از دستور /start ثبت‌نام کنید.");
        return;
    }
    
    $profileText = "📖 <b>پروفایل شما</b>\n\n" .
                   "👤 <b>نام و نام خانوادگی:</b> {$userData['name']}\n" .
                   "🏢 <b>شرکت:</b> {$userData['company']}\n" .
                   "💼 <b>تخصص:</b> {$userData['expertise']}\n" .
                   "📧 <b>ایمیل:</b> {$userData['email']}\n\n" .
                   "📋 <b>انگیزه‌نامه:</b>\n{$userData['motivation']}\n\n";

    if ($userData['verification_type']) {
        if ($userData['verification_type'] === 'linkedin') {
            $profileText .= "🔗 <b>لینک LinkedIn:</b>\n{$userData['verification_value']}\n";
        } elseif ($userData['verification_type'] === 'resume') {
            $profileText .= "📄 <b>لینک رزومه:</b>\n{$userData['verification_value']}\n";
        } elseif ($userData['verification_type'] === 'referral') {
            $profileText .= "👥 <b>معرف:</b>\n{$userData['verification_ref_name']} ({$userData['verification_value']})\n";
        }
    }
    
    $status = $userData['status'] ?? 'در انتظار بررسی';
    $profileText .= "\n🔍 <b>وضعیت درخواست:</b> {$status}";
    
    // اگر درخواست رد شده و دلیلی برای آن ثبت شده باشد
    if ($status === 'رد شده' && $userData['rejection_reason']) {
        $profileText .= "\n<b>دلیل رد درخواست:</b> {$userData['rejection_reason']}";
        
        // اضافه کردن اطلاعات بررسی‌کننده
        if ($userData['reviewed_by_username']) {
            $profileText .= "\n<b>بررسی شده توسط:</b> @{$userData['reviewed_by_username']}";
        }
    } elseif ($status === 'تایید شده' && $userData['reviewed_by_username']) {
        $profileText .= "\n<b>تایید شده توسط:</b> @{$userData['reviewed_by_username']}";
        if ($userData['rejection_reason']) { // در اینجا rejection_reason به عنوان دلیل تایید استفاده میشه
            $profileText .= "\n<b>دلیل تایید:</b> {$userData['rejection_reason']}";
        }
    }
    
    $keyboard = [
        [['text' => 'ویرایش پروفایل', 'callback_data' => 'edit_profile']],
        [['text' => 'ارسال مجدد برای بررسی', 'callback_data' => 'resubmit_profile']]
    ];
    
    sendMessage($chat_id, $profileText, $keyboard);
}

// دریافت و پردازش پیام‌های ورودی
$update = json_decode(file_get_contents('php://input'), true);

// ذخیره لاگ برای دیباگ
file_put_contents('request_log.txt', date('Y-m-d H:i:s') . ': ' . print_r($update, true) . "\n", FILE_APPEND);

if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $user_id = $message['from']['id'];
    
    // بررسی که آیا کاربر قبلا ثبت‌نام کرده است
    $existingUser = getUserData($chat_id);
    $user_state = getUserState($chat_id);
    
    // بررسی وضعیت انتظار برای دلیل رد یا تایید درخواست
    if (preg_match('/^AWAIT_(APPROVE|REJECT)_REASON_(.+)$/', $user_state, $matches)) {
        $action = strtolower($matches[1]);
        $applicant_user_id = $matches[2];
        handleApplicationResponse($action, $applicant_user_id, $chat_id, null, $text);
        setUserState($chat_id, null);
        return;
    }
    
    switch ($text) {
        case '/start':
            if (!checkChannelMembership($chat_id, $user_id)) {
                $keyboard = [[['text' => 'عضویت در کانال', 'url' => 't.me/irnog']]];
                sendMessage($chat_id, 
                    "سلام 👋\n" .
                    "به ربات احراز هویت و درخواست عضویت IRNOG خوش‌آمدید! 🌟\n\n" .
                    "گروه گردانندگان شبکۀ اینترنت ایران (Iranian Internet Network Operators Group)، یک اجتماع فنی، غیرانتفاعی و مستقل، از فعالان حوزۀ زیرساخت، شبکه و اینترنت در ایران است که با هدف ارتقاء دانش فنی، تسهیل ارتباطات بین‌اپراتوری، و ترویج فرهنگ همکاری و اشتراک‌گذاری دانش شکل گرفته است.\n\n" .
                    "در همین راستا، این گروه با گرد هم آوردن مجموعه‌ای از متخصصان و مهندسان شبکه و زیرساخت، ارائه‌دهندگان خدمات اینترنت و مراکز داده و سایر فعالان فنی تلاش می‌کند بستری حرفه‌ای برای تبادل تجربیات، اشتراک‌گذاری دانش و بررسی چالش‌های فنی و زیرساختی فراهم آورد.\n\n" .
                    "⚠️ پیش از ادامۀ فرآیند ثبت‌نام، لطفاً در کانال تلگرام IRNOG عضو شوید و پس از عضویت، مجدداً بات را استارت کنید.", $keyboard);
                return;
            }
            
            // بررسی اینکه آیا کاربر قبلا ثبت‌نام کرده است
            if ($existingUser && $existingUser['name']) {
                $name = $existingUser['name'];
                sendMessage($chat_id, "سلام {$name} عزیز 👋\n\nخوش‌آمدید. از منوی زیر می‌توانید گزینه مورد نظر خود را انتخاب کنید:", [
                    [['text' => 'مشاهده پروفایل', 'callback_data' => 'view_profile']],
                    [['text' => 'ویرایش پروفایل', 'callback_data' => 'edit_profile']],
                    [['text' => 'ارسال مجدد برای بررسی', 'callback_data' => 'resubmit_profile']]
                ]);
                return;
            }
            
            setUserState($chat_id, 'AWAIT_NAME');
            sendMessage($chat_id, "لطفاً نام و نام خانوادگی خود را وارد کنید:");
            break;
            
        case '/profile':
            showUserProfile($chat_id);
            break;
            
        default:
            switch ($user_state) {
                case 'AWAIT_NAME':
                    saveUserData($chat_id, 'name', $text);
                    setUserState($chat_id, 'AWAIT_COMPANY_INPUT');
                    sendMessage($chat_id, " لطفاً نام شرکت خود را وارد کنید یا در صورتی که به صورت فریلنسری فعالیت می‌کنید، عنوان «فریلنسر» را وارد نمایید:");
                    break;
                    
                case 'AWAIT_COMPANY_INPUT':
                    saveUserData($chat_id, 'company', $text);
                    setUserState($chat_id, 'AWAIT_EXPERTISE');
                    sendMessage($chat_id, "لطفاً حوزه تخصصی خود را وارد کنید:");
                    break;
                    
                case 'AWAIT_EXPERTISE':
                    saveUserData($chat_id, 'expertise', $text);
                    setUserState($chat_id, 'AWAIT_EMAIL');
                    sendMessage($chat_id, "لطفاً ایمیل سازمانی خود را وارد کنید:");
                    break;
                    
                case 'AWAIT_EMAIL':
                    saveUserData($chat_id, 'email', $text);
                    setUserState($chat_id, 'AWAIT_MOTIVATION');
                    sendMessage($chat_id, 
                        "هدف شما از عضویت در کامیونیتی ایرناگ چیست؟\n\n" );
                    break;
                    
                case 'AWAIT_MOTIVATION':
                    saveUserData($chat_id, 'motivation', $text);
                    setUserState($chat_id, 'AWAIT_VERIFICATION');
                    $keyboard = [
                        [['text' => 'LinkedIn پروفایل', 'callback_data' => 'verify_linkedin']],
                        [['text' => 'آپلود رزومه', 'callback_data' => 'verify_resume']],
                        [['text' => 'معرفی توسط اعضای تیم پی سی', 'callback_data' => 'verify_member']]
                    ];
                    sendMessage($chat_id, "لطفاً روش احراز هویت را انتخاب کنید:", $keyboard);
                    break;
                
                case 'AWAIT_LINKEDIN':
                    if (!isValidLinkedInUrl($text)) {
                        sendMessage($chat_id, "❌ لینک وارد شده معتبر نیست. لطفاً یک لینک LinkedIn معتبر وارد کنید:");
                        return;
                    }
                    
                    saveVerificationData($chat_id, 'linkedin', $text);
                    finalizeRegistration($chat_id);
                    break;
                    
                case 'AWAIT_RESUME':
                    if (!isValidUrl($text)) {
                        sendMessage($chat_id, "❌ لینک وارد شده معتبر نیست. لطفاً یک لینک معتبر برای رزومه خود وارد کنید:");
                        return;
                    }
                    
                    saveVerificationData($chat_id, 'resume', $text);
                    finalizeRegistration($chat_id);
                    break;
                    
                case 'AWAIT_REFERRAL_NAME':
                    saveVerificationData($chat_id, 'referral', '', $text);
                    finalizeRegistration($chat_id);
                    break;
                
                case 'AWAIT_REFERRAL_ID':
                    $refName = getUserData($chat_id, 'verification_ref_name');
                    saveVerificationData($chat_id, 'referral', $text, $refName);
                    finalizeRegistration($chat_id);
                    break;
                    
                case 'EDIT_NAME':
                    saveUserData($chat_id, 'name', $text);
                    setUserState($chat_id, 'PROFILE_EDIT');
                    showUserProfile($chat_id);
                    break;
                    
                case 'EDIT_COMPANY':
                    saveUserData($chat_id, 'company', $text);
                    setUserState($chat_id, 'PROFILE_EDIT');
                    showUserProfile($chat_id);
                    break;
                    
                case 'EDIT_EXPERTISE':
                    saveUserData($chat_id, 'expertise', $text);
                    setUserState($chat_id, 'PROFILE_EDIT');
                    showUserProfile($chat_id);
                    break;
                    
                case 'EDIT_EMAIL':
                    saveUserData($chat_id, 'email', $text);
                    setUserState($chat_id, 'PROFILE_EDIT');
                    showUserProfile($chat_id);
                    break;
                    
                case 'EDIT_MOTIVATION':
                    saveUserData($chat_id, 'motivation', $text);
                    setUserState($chat_id, 'PROFILE_EDIT');
                    showUserProfile($chat_id);
                    break;
            }
    }
}

if (isset($update['callback_query'])) {
    $callback_query = $update['callback_query'];
    $chat_id = $callback_query['message']['chat']['id'];
    $data = $callback_query['data'];
    $message_id = $callback_query['message']['message_id'];
    $from_user = $callback_query['from'];
    $from_user_id = $from_user['id'];
    $from_username = $from_user['username'] ?? $from_user['first_name'];
    
    // اگر تایید یا رد درخواست عضویت است
    if (strpos($data, 'approve_') === 0) {
        $user_id = substr($data, 8);
        
        // بررسی عضویت در گروه بررسی
        if (!checkReviewGroupMembership($from_user_id)) {
            sendMessage($from_user_id, "❌ شما مجاز به انجام این عمل نیستید.");
            return;
        }
        
        // درخواست دلیل تایید در پیوی
        setUserState($from_user_id, 'AWAIT_APPROVE_REASON_' . $user_id);
        sendMessage($from_user_id, "لطفاً دلیل تایید درخواست را وارد کنید (اختیاری - می‌توانید فقط 'تایید' بنویسید):");
        
        // آپدیت پیام در گروه
        $userData = getUserData($user_id);
        $updatedMessage = "🔄 <b>در حال بررسی توسط @{$from_username}</b>\n\n" .
                         "👤 نام: {$userData['name']}\n" .
                         "🏢 شرکت: {$userData['company']}\n" .
                         "💼 تخصص: {$userData['expertise']}\n" .
                         "📧 ایمیل: {$userData['email']}\n\n" .
                         "📋 انگیزه‌نامه:\n{$userData['motivation']}\n\n";
        
        if ($userData['verification_type'] === 'linkedin') {
            $updatedMessage .= "🔗 لینک LinkedIn:\n{$userData['verification_value']}\n";
        } elseif ($userData['verification_type'] === 'resume') {
            $updatedMessage .= "📄 لینک رزومه:\n{$userData['verification_value']}\n";
        } elseif ($userData['verification_type'] === 'referral') {
            $updatedMessage .= "👥 معرف:\n{$userData['verification_ref_name']} ({$userData['verification_value']})\n";
        }
        
        editMessageText($chat_id, $message_id, $updatedMessage);
        return;
        
    } elseif (strpos($data, 'reject_') === 0 && strpos($data, 'reject_reason_') !== 0) {
        $user_id = substr($data, 7);
        
        // بررسی عضویت در گروه بررسی
        if (!checkReviewGroupMembership($from_user_id)) {
            sendMessage($from_user_id, "❌ شما مجاز به انجام این عمل نیستید.");
            return;
        }
        
        // درخواست دلیل رد در پیوی
        setUserState($from_user_id, 'AWAIT_REJECT_REASON_' . $user_id);
        sendMessage($from_user_id, "لطفاً دلیل رد درخواست کاربر را وارد کنید:");
        
        // آپدیت پیام در گروه
        $userData = getUserData($user_id);
        $updatedMessage = "🔄 <b>در حال بررسی توسط @{$from_username}</b>\n\n" .
                         "👤 نام: {$userData['name']}\n" .
                         "🏢 شرکت: {$userData['company']}\n" .
                         "💼 تخصص: {$userData['expertise']}\n" .
                         "📧 ایمیل: {$userData['email']}\n\n" .
                         "📋 انگیزه‌نامه:\n{$userData['motivation']}\n\n";
        
        if ($userData['verification_type'] === 'linkedin') {
            $updatedMessage .= "🔗 لینک LinkedIn:\n{$userData['verification_value']}\n";
        } elseif ($userData['verification_type'] === 'resume') {
            $updatedMessage .= "📄 لینک رزومه:\n{$userData['verification_value']}\n";
        } elseif ($userData['verification_type'] === 'referral') {
            $updatedMessage .= "👥 معرف:\n{$userData['verification_ref_name']} ({$userData['verification_value']})\n";
        }
        
        editMessageText($chat_id, $message_id, $updatedMessage);
        return;
    }
    
    switch ($data) {
        case 'verify_linkedin':
            setUserState($chat_id, 'AWAIT_LINKEDIN');
            sendMessage($chat_id, "لطفاً لینک پروفایل LinkedIn خود را ارسال کنید (فرمت لینک باید معتبر باشد):");
            break;
            
        case 'verify_resume':
            setUserState($chat_id, 'AWAIT_RESUME');
            sendMessage($chat_id, "لطفاً لینک رزومه خود را ارسال کنید (فرمت لینک باید معتبر باشد):");
            break;
            
        case 'verify_member':
            setUserState($chat_id, 'AWAIT_REFERRAL_NAME');
            sendMessage($chat_id, "لطفاً نام و نام خانوادگی عضو معرف را وارد کنید:");
            break;
            
        case 'view_profile':
            showUserProfile($chat_id);
            break;
            
        case 'edit_profile':
            $keyboard = [
                [['text' => 'نام و نام خانوادگی', 'callback_data' => 'edit_name']],
                [['text' => 'شرکت', 'callback_data' => 'edit_company']],
                [['text' => 'تخصص', 'callback_data' => 'edit_expertise']],
                [['text' => 'ایمیل', 'callback_data' => 'edit_email']],
                [['text' => 'انگیزه‌نامه', 'callback_data' => 'edit_motivation']],
                [['text' => 'روش احراز هویت', 'callback_data' => 'edit_verification']],
                [['text' => 'بازگشت', 'callback_data' => 'view_profile']]
            ];
            
            sendMessage($chat_id, "لطفاً فیلدی که می‌خواهید ویرایش کنید را انتخاب کنید:", $keyboard);
            break;
            
        case 'edit_name':
            setUserState($chat_id, 'EDIT_NAME');
            sendMessage($chat_id, "لطفاً نام و نام خانوادگی جدید خود را وارد کنید:");
            break;
            
        case 'edit_company':
            setUserState($chat_id, 'EDIT_COMPANY');
            sendMessage($chat_id, "لطفاً نام شرکت جدید خود را وارد کنید:");
            break;
            
        case 'edit_expertise':
            setUserState($chat_id, 'EDIT_EXPERTISE');
            sendMessage($chat_id, "لطفاً حوزه تخصصی جدید خود را وارد کنید:");
            break;
            
        case 'edit_email':
            setUserState($chat_id, 'EDIT_EMAIL');
            sendMessage($chat_id, "لطفاً ایمیل جدید خود را وارد کنید:");
            break;
            
        case 'edit_motivation':
            setUserState($chat_id, 'EDIT_MOTIVATION');
            sendMessage($chat_id, 
                "لطفاً انگیزه‌نامه جدید خود را با پاسخ به سوالات زیر بنویسید:\n\n" .
                "1. چه تجربیاتی در زمینه مدیریت و توسعه شبکه‌های اینترنتی دارید؟\n" .
                "2. چگونه می‌توانید به بهبود شرایط استفاده از اینترنت در ایران کمک کنید؟\n" .
                "3. دیدگاه شما درباره چالش‌های فعلی اینترنت ایران و راهکارهای پیشنهادی چیست؟\n" .
                "4. چگونه می‌توانید در فعالیت‌های مشورتی و راهبردی IRNOG مشارکت کنید?");
            break;
            
        case 'edit_verification':
            setUserState($chat_id, 'AWAIT_VERIFICATION');
            $keyboard = [
                [['text' => 'LinkedIn پروفایل', 'callback_data' => 'verify_linkedin']],
                [['text' => 'آپلود رزومه', 'callback_data' => 'verify_resume']],
                [['text' => 'معرفی توسط اعضای تیم پی سی', 'callback_data' => 'verify_member']]
            ];
            sendMessage($chat_id, "لطفاً روش احراز هویت جدید را انتخاب کنید:", $keyboard);
            break;
            
        case 'resubmit_profile':
            $userData = getUserData($chat_id);
            
            if (!$userData) {
                sendMessage($chat_id, "اطلاعاتی برای ارسال مجدد وجود ندارد. لطفاً ابتدا ثبت‌نام کنید.");
                return;
            }
            
            saveUserData($chat_id, 'status', 'در انتظار بررسی');
            saveUserData($chat_id, 'rejection_reason', null);
            saveUserData($chat_id, 'reviewed_by_user_id', null);
            saveUserData($chat_id, 'reviewed_by_username', null);
            saveUserData($chat_id, 'review_decision', null);
            
            // آماده‌سازی پیام برای گروه بررسی
            $groupMessage = "🔍 درخواست عضویت مجدد:\n\n" .
                           "👤 نام: {$userData['name']}\n" .
                           "🏢 شرکت: {$userData['company']}\n" .
                           "💼 تخصص: {$userData['expertise']}\n" .
                           "📧 ایمیل: {$userData['email']}\n\n" .
                           "📋 انگیزه‌نامه:\n{$userData['motivation']}\n\n";

            if ($userData['verification_type'] === 'linkedin') {
                $groupMessage .= "🔗 لینک LinkedIn:\n{$userData['verification_value']}\n";
            } elseif ($userData['verification_type'] === 'resume') {
                $groupMessage .= "📄 لینک رزومه:\n{$userData['verification_value']}\n";
            } elseif ($userData['verification_type'] === 'referral') {
                $groupMessage .= "👥 معرف:\n{$userData['verification_ref_name']} ({$userData['verification_value']})\n";
            }
            
            // اضافه کردن دکمه‌های تایید و رد برای گروه
            $keyboard = [
                [
                    ['text' => '✅ تایید درخواست', 'callback_data' => 'approve_' . $chat_id],
                    ['text' => '❌ رد درخواست', 'callback_data' => 'reject_' . $chat_id]
                ]
            ];
            
            // ارسال به گروه بررسی
            sendMessage(REVIEW_GROUP_ID, $groupMessage, $keyboard);
            
            sendMessage($chat_id, "✅ درخواست شما مجددا برای بررسی ارسال شد. نتیجه بررسی به شما اطلاع داده خواهد شد.");
            break;
    }
}

function finalizeRegistration($chat_id) {
    setUserState($chat_id, 'COMPLETED');
    saveUserData($chat_id, 'status', 'در انتظار بررسی');
    
    // دریافت اطلاعات کاربر
    $userData = getUserData($chat_id);
    
    // آماده‌سازی پیام برای گروه بررسی
    $groupMessage = "🔍 درخواست عضویت جدید:\n\n" .
                   "👤 نام: {$userData['name']}\n" .
                   "🏢 شرکت: {$userData['company']}\n" .
                   "💼 تخصص: {$userData['expertise']}\n" .
                   "📧 ایمیل: {$userData['email']}\n\n" .
                   "📋 انگیزه‌نامه:\n{$userData['motivation']}\n\n";

    if ($userData['verification_type'] === 'linkedin') {
        $groupMessage .= "🔗 لینک LinkedIn:\n{$userData['verification_value']}\n";
    } elseif ($userData['verification_type'] === 'resume') {
        $groupMessage .= "📄 لینک رزومه:\n{$userData['verification_value']}\n";
    } elseif ($userData['verification_type'] === 'referral') {
        $groupMessage .= "👥 معرف:\n{$userData['verification_ref_name']} ({$userData['verification_value']})\n";
    }
    
    // اضافه کردن دکمه‌های تایید و رد برای گروه
    $keyboard = [
        [
            ['text' => '✅ تایید درخواست', 'callback_data' => 'approve_' . $chat_id],
            ['text' => '❌ رد درخواست', 'callback_data' => 'reject_' . $chat_id]
        ]
    ];
    
    // ارسال به گروه بررسی
    $groupResult = sendMessage(REVIEW_GROUP_ID, $groupMessage, $keyboard);
    
    // لاگ نتیجه ارسال برای دیباگ
    file_put_contents('group_message_log.txt', date('Y-m-d H:i:s') . ': ' . print_r($groupResult, true) . "\n", FILE_APPEND);
    
    // ارسال پیام تأیید به کاربر و نمایش پروفایل
    sendMessage($chat_id, 
        "✅ اطلاعات شما با موفقیت ثبت شد.\n\n" .
        "درخواست شما توسط تیم PC بررسی خواهد شد و پس از تأیید، لینک گروه برای شما ارسال می‌شود.\n\n" .
        "با تشکر از عضویت شما در IRNOG 🌟");
        
    // نمایش پروفایل کاربر
    showUserProfile($chat_id);
}

/**
 * تابع پردازش پاسخ اعضای گروه به درخواست‌های عضویت
 */
function handleApplicationResponse($action, $user_id, $reviewer_chat_id, $message_id = null, $reason = null) {
    // ثبت لاگ برای دیباگ
    file_put_contents('debug_actions_log.txt', date('Y-m-d H:i:s') . ': action=' . $action . ', user_id=' . $user_id . ', reviewer=' . $reviewer_chat_id . "\n", FILE_APPEND);
    
    $isApproved = ($action === 'approve');
    $userData = getUserData($user_id);
    
    if (!$userData) {
        sendMessage($reviewer_chat_id, "❌ خطا: اطلاعات کاربر یافت نشد.");
        return;
    }
    
    // دریافت اطلاعات بررسی‌کننده
    $reviewerInfo = makeHTTPRequest('getChat', ['chat_id' => $reviewer_chat_id]);
    $reviewerUsername = '';
    
    if (isset($reviewerInfo['result']['username'])) {
        $reviewerUsername = $reviewerInfo['result']['username'];
    } elseif (isset($reviewerInfo['result']['first_name'])) {
        $reviewerUsername = $reviewerInfo['result']['first_name'];
    }
    
    $name = $userData['name'];
    
    if ($isApproved) {
        // Approve request
        saveUserData($user_id, 'status', 'تایید شده');
        saveUserData($user_id, 'rejection_reason', $reason); // در اینجا به عنوان دلیل تایید استفاده میشه
        saveUserData($user_id, 'reviewed_by_user_id', $reviewer_chat_id);
        saveUserData($user_id, 'reviewed_by_username', $reviewerUsername);
        saveUserData($user_id, 'review_decision', 'approved');
        
        // Send message to user
        $userMessage = "🎉 <b>تبریک!</b>\n\n" .
                      "درخواست عضویت شما در IRNOG تایید شد.\n\n";
        
        if ($reason && trim($reason) !== '' && strtolower(trim($reason)) !== 'تایید') {
            $userMessage .= "<b>دلیل تایید:</b> {$reason}\n\n";
        }
        
        $userMessage .= "برای ورود به گروه اصلی می‌توانید از لینک زیر استفاده کنید:\n" . GROUP_LINK;
        
        $keyboardUser = [[['text' => 'ورود به گروه', 'url' => GROUP_LINK]]];
        sendMessage($user_id, $userMessage, $keyboardUser);
        
        // Send confirmation to reviewer
        sendMessage($reviewer_chat_id, "✅ درخواست {$name} با موفقیت تایید شد و لینک گروه برای ایشان ارسال گردید.");
        
        // Send report to review group
        $reportMessage = "✅ <b>گزارش تایید درخواست</b>\n\n" .
                        "👤 <b>متقاضی:</b> {$name}\n" .
                        "👨‍💼 <b>تایید شده توسط:</b> @{$reviewerUsername}\n";
        
        if ($reason && trim($reason) !== '' && strtolower(trim($reason)) !== 'تایید') {
            $reportMessage .= "📝 <b>دلیل تایید:</b> {$reason}\n";
        }
        
        $reportMessage .= "\n🔗 لینک گروه برای متقاضی ارسال شد.";
        
        sendMessage(REVIEW_GROUP_ID, $reportMessage);
        
    } else {
        // Reject request
        saveUserData($user_id, 'status', 'رد شده');
        saveUserData($user_id, 'reviewed_by_user_id', $reviewer_chat_id);
        saveUserData($user_id, 'reviewed_by_username', $reviewerUsername);
        saveUserData($user_id, 'review_decision', 'rejected');
        
        // Save rejection reason if provided
        if ($reason) {
            saveUserData($user_id, 'rejection_reason', $reason);
        }
        
        // Send message to user
        $userMessage = "❌ <b>اطلاعیه</b>\n\n" .
                      "متأسفانه درخواست عضویت شما در IRNOG در این مرحله تایید نشد.";
        
        // Add rejection reason if provided
        if ($reason) {
            $userMessage .= "\n\n<b>دلیل:</b> {$reason}";
        }
        
        $userMessage .= "\n\nشما می‌توانید پس از تکمیل اطلاعات خود، مجدداً درخواست خود را ارسال نمایید.";
        
        sendMessage($user_id, $userMessage);
        
        // Send confirmation to reviewer
        sendMessage($reviewer_chat_id, "❌ درخواست {$name} با موفقیت رد شد و به کاربر اطلاع داده شد.");
        
        // Send report to review group
        $reportMessage = "❌ <b>گزارش رد درخواست</b>\n\n" .
                        "👤 <b>متقاضی:</b> {$name}\n" .
                        "👨‍💼 <b>رد شده توسط:</b> @{$reviewerUsername}\n";
        
        if ($reason) {
            $reportMessage .= "📝 <b>دلیل رد:</b> {$reason}\n";
        }
        
        $reportMessage .= "\n📢 متقاضی می‌تواند پس از اصلاح مجدداً درخواست دهد.";
        
        sendMessage(REVIEW_GROUP_ID, $reportMessage);
    }
    
    // Log for debugging
    $logEntry = date('Y-m-d H:i:s') . ': درخواست ' . $user_id . ' (' . $name . ') ' . 
               ($isApproved ? 'تایید' : 'رد') . ' شده توسط ' . $reviewerUsername;
    
    if ($reason) {
        $logEntry .= " با دلیل: " . $reason;
    }
    
    $logEntry .= "\n";
    file_put_contents('group_review_actions_log.txt', $logEntry, FILE_APPEND);
    
    return true;
}
