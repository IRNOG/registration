<?php

// بارگذاری تنظیمات از فایل .env
if (file_exists('.env')) {
    include '.env'; 
}

// تعریف ثابت‌ها با استفاده از متغیرهای محیطی
define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('CHANNEL_ID', getenv('CHANNEL_ID'));
define('ADMIN_ID', getenv('ADMIN_ID'));
define('USERS_FILE', getenv('USERS_FILE') ?: 'users.json');
define('GROUP_LINK', getenv('GROUP_LINK'));

// توابع کمکی برای کار با فایل JSON
function loadUsersData() {
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([]));
        return [];
    }
    return json_decode(file_get_contents(USERS_FILE), true) ?? [];
}

function saveUsersData($data) {
    file_put_contents(USERS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function getUserState($chat_id) {
    $users = loadUsersData();
    return $users[$chat_id]['state'] ?? null;
}

function setUserState($chat_id, $state) {
    $users = loadUsersData();
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = ['state' => $state, 'data' => []];
    } else {
        $users[$chat_id]['state'] = $state;
    }
    saveUsersData($users);
}

function saveUserData($chat_id, $field, $value) {
    $users = loadUsersData();
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = ['state' => null, 'data' => []];
    }
    $users[$chat_id]['data'][$field] = $value;
    saveUsersData($users);
}

function getUserData($chat_id, $field = null) {
    $users = loadUsersData();
    if (!isset($users[$chat_id])) {
        return null;
    }
    
    if ($field === null) {
        return $users[$chat_id]['data'] ?? null;
    }
    
    return $users[$chat_id]['data'][$field] ?? null;
}

function getUserByTelegramId($chat_id) {
    $users = loadUsersData();
    return $users[$chat_id] ?? null;
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

function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function isValidLinkedInUrl($url) {
    return isValidUrl($url) && strpos($url, 'linkedin.com') !== false;
}

function getCompanyKeyboard() {
    // لیست شرکت‌ها در سه ردیف
    $companies = [
        'آروان کلاد', 'ایرانسل', 'پیشگامان توسعه ارتباطات', 'هم‌روش',
        'اسنپ', 'چکاوک', 'افرانت', 'همراه اول',
        'ابردراک', 'ستون', 'شرکت مخابرات ایران', 'سایر'
    ];
    
    $keyboard = [];
    $row = [];
    
    foreach ($companies as $index => $company) {
        $row[] = ['text' => $company, 'callback_data' => 'company_' . $company];
        
        if (($index + 1) % 4 == 0 || $index == count($companies) - 1) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    
    return $keyboard;
}

function showUserProfile($chat_id) {
    $userData = getUserData($chat_id);
    
    if (!$userData) {
        sendMessage($chat_id, "اطلاعاتی برای شما ثبت نشده است. لطفاً با استفاده از دستور /start ثبت‌نام کنید.");
        return;
    }
    
    $profileText = "🔖 <b>پروفایل شما</b>\n\n" .
                   "👤 <b>نام و نام خانوادگی:</b> {$userData['name']}\n" .
                   "🏢 <b>شرکت:</b> {$userData['company']}\n" .
                   "💼 <b>تخصص:</b> {$userData['expertise']}\n" .
                   "📧 <b>ایمیل:</b> {$userData['email']}\n\n" .
                   "📋 <b>انگیزه‌نامه:</b>\n{$userData['motivation']}\n\n";

    if (isset($userData['verification'])) {
        if ($userData['verification']['type'] === 'linkedin') {
            $profileText .= "🔗 <b>لینک LinkedIn:</b>\n{$userData['verification']['value']}\n";
        } elseif ($userData['verification']['type'] === 'resume') {
            $profileText .= "📄 <b>لینک رزومه:</b>\n{$userData['verification']['value']}\n";
        } elseif ($userData['verification']['type'] === 'referral') {
            $profileText .= "👥 <b>معرف:</b>\n{$userData['verification']['ref_name']} ({$userData['verification']['value']})\n";
        }
    }
    
    $status = $userData['status'] ?? 'در انتظار بررسی';
    $profileText .= "\n🔍 <b>وضعیت درخواست:</b> {$status}";
    
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
    $existingUser = getUserByTelegramId($chat_id);
    $user_state = getUserState($chat_id);
    
    switch ($text) {
        case '/start':
            if (!checkChannelMembership($chat_id, $user_id)) {
                $keyboard = [[['text' => 'عضویت در کانال', 'url' => 't.me/irnog']]];
                sendMessage($chat_id, 
                    "سلام 👋\n\n" .
                    "به ربات درخواست عضویت IRNOG خوش‌آمدید! 🌟\n\n" .
                    "هدف از تاسیس IRNOG، ایجاد گروهی تخصصی با ساز و کار شفاف است که در آن، متخصصان و مدیران فنی و اجرایی اینترنت ایران:\n\n" .
                    "🔹 به ارائه پیشنهادات کارشناسانه برای بهبود شرایط استفاده از اینترنت می‌پردازند\n" .
                    "🔹 راهکارهای افزایش نفوذ و بهره‌وری اینترنت را بررسی می‌کنند\n" . 
                    "🔹 امکان مشارکت در گروه‌ها و سازمان‌های سیاست‌گذار را می‌یابند\n" .
                    "🔹 به تبادل تجربیات و دانش فنی می‌پردازند\n\n" .
                    "❗️ پیش از ادامه فرآیند ثبت‌نام، لطفاً در کانال تلگرام IRNOG عضو شوید:", $keyboard);
                return;
            }
            
            // بررسی اینکه آیا کاربر قبلا ثبت‌نام کرده است
            if ($existingUser && isset($existingUser['data']['name'])) {
                $name = $existingUser['data']['name'];
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
                    setUserState($chat_id, 'AWAIT_COMPANY_SELECTION');
                    sendMessage($chat_id, "لطفاً شرکت خود را از لیست زیر انتخاب کنید:", getCompanyKeyboard());
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
                        "لطفاً انگیزه‌نامه خود را با پاسخ به سوالات زیر بنویسید:\n\n" .
                        "1. چه تجربیاتی در زمینه مدیریت و توسعه شبکه‌های اینترنتی دارید؟\n" .
                        "2. چگونه می‌توانید به بهبود شرایط استفاده از اینترنت در ایران کمک کنید؟\n" .
                        "3. دیدگاه شما درباره چالش‌های فعلی اینترنت ایران و راهکارهای پیشنهادی چیست؟\n" .
                        "4. چگونه می‌توانید در فعالیت‌های مشورتی و راهبردی IRNOG مشارکت کنید?");
                    break;
                    
                case 'AWAIT_MOTIVATION':
                    saveUserData($chat_id, 'motivation', $text);
                    setUserState($chat_id, 'AWAIT_VERIFICATION');
                    $keyboard = [
                        [['text' => 'LinkedIn پروفایل', 'callback_data' => 'verify_linkedin']],
                        [['text' => 'آپلود رزومه', 'callback_data' => 'verify_resume']],
                        [['text' => 'معرفی توسط عضو', 'callback_data' => 'verify_member']]
                    ];
                    sendMessage($chat_id, "لطفاً روش احراز هویت را انتخاب کنید:", $keyboard);
                    break;
                
                case 'AWAIT_LINKEDIN':
                    if (!isValidLinkedInUrl($text)) {
                        sendMessage($chat_id, "❌ لینک وارد شده معتبر نیست. لطفاً یک لینک LinkedIn معتبر وارد کنید:");
                        return;
                    }
                    
                    saveUserData($chat_id, 'verification', ['type' => 'linkedin', 'value' => $text]);
                    finalizeRegistration($chat_id);
                    break;
                    
                case 'AWAIT_RESUME':
                    if (!isValidUrl($text)) {
                        sendMessage($chat_id, "❌ لینک وارد شده معتبر نیست. لطفاً یک لینک معتبر برای رزومه خود وارد کنید:");
                        return;
                    }
                    
                    saveUserData($chat_id, 'verification', ['type' => 'resume', 'value' => $text]);
                    finalizeRegistration($chat_id);
                    break;
                    
                case 'AWAIT_REFERRAL_NAME':
                    saveUserData($chat_id, 'referral_name', $text);
                    setUserState($chat_id, 'AWAIT_REFERRAL_ID');
                    sendMessage($chat_id, "لطفاً آیدی تلگرام عضو معرف را وارد کنید:");
                    break;
                    
                case 'AWAIT_REFERRAL_ID':
                    saveUserData($chat_id, 'verification', [
                        'type' => 'referral', 
                        'value' => $text,
                        'ref_name' => getUserData($chat_id, 'referral_name')
                    ]);
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
    
    // اگر انتخاب شرکت است
    if (strpos($data, 'company_') === 0) {
        $company = substr($data, 8);
        
        if ($company === 'سایر') {
            setUserState($chat_id, 'AWAIT_COMPANY_INPUT');
            sendMessage($chat_id, "لطفاً نام شرکت خود را وارد کنید:");
        } else {
            saveUserData($chat_id, 'company', $company);
            setUserState($chat_id, 'AWAIT_EXPERTISE');
            sendMessage($chat_id, "لطفاً حوزه تخصصی خود را وارد کنید:");
        }
        
        // پاک کردن پیام انتخاب شرکت
        makeHTTPRequest('deleteMessage', [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ]);
        
        return;
    }
    
    // اگر تایید یا رد درخواست عضویت است
    if (strpos($data, 'approve_') === 0) {
        $user_id = substr($data, 8);
        handleApplicationResponse('approve', $user_id, $chat_id, $message_id);
        return;
    } elseif (strpos($data, 'reject_') === 0) {
        $user_id = substr($data, 7);
        handleApplicationResponse('reject', $user_id, $chat_id, $message_id);
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
            setUserState($chat_id, 'AWAIT_COMPANY_SELECTION');
            sendMessage($chat_id, "لطفاً شرکت خود را از لیست زیر انتخاب کنید:", getCompanyKeyboard());
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
                [['text' => 'معرفی توسط عضو', 'callback_data' => 'verify_member']]
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
            
            // آماده‌سازی پیام برای ادمین
            $adminMessage = "📝 درخواست عضویت مجدد:\n\n" .
                           "👤 نام: {$userData['name']}\n" .
                           "🏢 شرکت: {$userData['company']}\n" .
                           "💼 تخصص: {$userData['expertise']}\n" .
                           "📧 ایمیل: {$userData['email']}\n\n" .
                           "📋 انگیزه‌نامه:\n{$userData['motivation']}\n\n";

            if ($userData['verification']['type'] === 'linkedin') {
                $adminMessage .= "🔗 لینک LinkedIn:\n{$userData['verification']['value']}\n";
            } elseif ($userData['verification']['type'] === 'resume') {
                $adminMessage .= "📄 لینک رزومه:\n{$userData['verification']['value']}\n";
            } elseif ($userData['verification']['type'] === 'referral') {
                $adminMessage .= "👥 معرف:\n{$userData['verification']['ref_name']} ({$userData['verification']['value']})\n";
            }
            
            // اضافه کردن دکمه‌های تایید و رد برای ادمین
            $keyboard = [
                [
                    ['text' => '✅ تایید درخواست', 'callback_data' => 'approve_' . $chat_id],
                    ['text' => '❌ رد درخواست', 'callback_data' => 'reject_' . $chat_id]
                ]
            ];
            
            // ثبت لاگ دکمه‌ها برای دیباگ
            file_put_contents('keyboard_debug.txt', 
                date('Y-m-d H:i:s') . ': approve=' . 'approve_' . $chat_id . 
                ', reject=' . 'reject_' . $chat_id . "\n", FILE_APPEND);
            
            // ارسال به ادمین
            sendMessage(ADMIN_ID, $adminMessage, $keyboard);
            
            sendMessage($chat_id, "✅ درخواست شما مجدداً برای بررسی ارسال شد. نتیجه بررسی به شما اطلاع داده خواهد شد.");
            break;
    }
}

function finalizeRegistration($chat_id) {
    setUserState($chat_id, 'COMPLETED');
    saveUserData($chat_id, 'status', 'در انتظار بررسی');
    
    // دریافت اطلاعات کاربر
    $userData = loadUsersData()[$chat_id]['data'];
    
    // آماده‌سازی پیام برای ادمین
    $adminMessage = "📝 درخواست عضویت جدید:\n\n" .
                   "👤 نام: {$userData['name']}\n" .
                   "🏢 شرکت: {$userData['company']}\n" .
                   "💼 تخصص: {$userData['expertise']}\n" .
                   "📧 ایمیل: {$userData['email']}\n\n" .
                   "📋 انگیزه‌نامه:\n{$userData['motivation']}\n\n";

    if ($userData['verification']['type'] === 'linkedin') {
        $adminMessage .= "🔗 لینک LinkedIn:\n{$userData['verification']['value']}\n";
    } elseif ($userData['verification']['type'] === 'resume') {
        $adminMessage .= "📄 لینک رزومه:\n{$userData['verification']['value']}\n";
    } elseif ($userData['verification']['type'] === 'referral') {
        $adminMessage .= "👥 معرف:\n{$userData['verification']['ref_name']} ({$userData['verification']['value']})\n";
    }
    
    // اضافه کردن دکمه‌های تایید و رد برای ادمین
    $keyboard = [
        [
            ['text' => '✅ تایید درخواست', 'callback_data' => 'approve_' . $chat_id],
            ['text' => '❌ رد درخواست', 'callback_data' => 'reject_' . $chat_id]
        ]
    ];
    
    // ثبت لاگ دکمه‌ها برای دیباگ
    file_put_contents('keyboard_debug.txt', 
        date('Y-m-d H:i:s') . ': approve=' . 'approve_' . $chat_id . 
        ', reject=' . 'reject_' . $chat_id . "\n", FILE_APPEND);
    
    // ارسال به ادمین با آیدی عددی
    $adminResult = sendMessage(ADMIN_ID, $adminMessage, $keyboard);
    
    // لاگ نتیجه ارسال برای دیباگ
    file_put_contents('admin_message_log.txt', date('Y-m-d H:i:s') . ': ' . print_r($adminResult, true) . "\n", FILE_APPEND);
    
    // ارسال پیام تأیید به کاربر و نمایش پروفایل
    sendMessage($chat_id, 
        "✅ اطلاعات شما با موفقیت ثبت شد.\n\n" .
        "درخواست شما توسط تیم PC بررسی خواهد شد و پس از تأیید، لینک گروه برای شما ارسال می‌شود.\n\n" .
        "با تشکر از عضویت شما در IRNOG 🌟");
        
    // نمایش پروفایل کاربر
    showUserProfile($chat_id);
}

/**
 * تابع پردازش پاسخ ادمین به درخواست‌های عضویت
 */
function handleApplicationResponse($action, $user_id, $admin_chat_id, $message_id) {
    // ثبت لاگ برای دیباگ
    file_put_contents('debug_actions_log.txt', date('Y-m-d H:i:s') . ': action=' . $action . ', user_id=' . $user_id . "\n", FILE_APPEND);
    
    $isApproved = ($action === 'approve');
    $userData = getUserData($user_id);
    
    if (!$userData) {
        editMessageText($admin_chat_id, $message_id, "❌ خطا: اطلاعات کاربر یافت نشد.");
        return;
    }
    
    $name = $userData['name'];
    
    if ($isApproved) {
        // تایید درخواست
        saveUserData($user_id, 'status', 'تایید شده');
        
        // ارسال پیام به کاربر
        $userMessage = "🎉 <b>تبریک!</b>\n\n" .
                      "درخواست عضویت شما در IRNOG تایید شد.\n\n" .
                      "برای ورود به گروه اصلی می‌توانید از لینک زیر استفاده کنید:\n" .
                      GROUP_LINK;
        
        $keyboardUser = [[['text' => 'ورود به گروه', 'url' => GROUP_LINK]]];
        sendMessage($user_id, $userMessage, $keyboardUser);
        
        // بروزرسانی پیام ادمین
        $adminMessage = "✅ درخواست عضویت {$name} تایید شد و لینک گروه برای ایشان ارسال گردید.";
        editMessageText($admin_chat_id, $message_id, $adminMessage);
    } else {
        // رد درخواست
        saveUserData($user_id, 'status', 'رد شده');
        
        // ارسال پیام به کاربر
        $userMessage = "❌ <b>اطلاعیه</b>\n\n" .
                      "متأسفانه درخواست عضویت شما در IRNOG در این مرحله تایید نشد.\n\n" .
                      "شما می‌توانید پس از تکمیل اطلاعات خود، مجدداً درخواست خود را ارسال نمایید.";
        
        sendMessage($user_id, $userMessage);
        
        // بروزرسانی پیام ادمین
        $adminMessage = "❌ درخواست عضویت {$name} رد شد و به کاربر اطلاع داده شد.";
        editMessageText($admin_chat_id, $message_id, $adminMessage);
    }
    
    // لاگ برای دیباگ
    $logEntry = date('Y-m-d H:i:s') . ': درخواست ' . $user_id . ' (' . $name . ') ' . 
               ($isApproved ? 'تایید' : 'رد') . " شد.\n";
    file_put_contents('admin_actions_log.txt', $logEntry, FILE_APPEND);
    
    return true; // اطمینان از اتمام موفقیت‌آمیز تابع
}
