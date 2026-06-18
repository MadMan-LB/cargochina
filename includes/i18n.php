<?php

require_once __DIR__ . '/../backend/config/runtime.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function clmsSupportedUiLocales(): array
{
    return ['en', 'zh-CN'];
}

function clmsNormalizeUiLocale(?string $locale): string
{
    $value = strtolower(trim((string) $locale));
    return match ($value) {
        'zh', 'zh-cn', 'zh_cn', 'cn', 'zh-hans' => 'zh-CN',
        default => 'en',
    };
}

function clmsUiTranslations(): array
{
    static $translations = null;
    if ($translations === null) {
        $translations = require __DIR__ . '/ui_translations.php';
    }
    return $translations;
}

function clmsSetUiLocale(?string $locale): string
{
    $normalized = clmsNormalizeUiLocale($locale);
    $_SESSION['clms_ui_locale'] = $normalized;
    $GLOBALS['clms_ui_locale_current'] = $normalized;
    setcookie('clms_ui_locale', $normalized, [
        'expires' => time() + (86400 * 365),
        'path' => '/cargochina',
        'samesite' => 'Lax',
    ]);
    return $normalized;
}

function clmsGetUiLocale(): string
{
    if (!empty($GLOBALS['clms_ui_locale_current'])) {
        return clmsNormalizeUiLocale($GLOBALS['clms_ui_locale_current']);
    }

    if (isset($_SESSION['clms_ui_locale'])) {
        $GLOBALS['clms_ui_locale_current'] = clmsNormalizeUiLocale($_SESSION['clms_ui_locale']);
        return $GLOBALS['clms_ui_locale_current'];
    }

    if (!empty($_COOKIE['clms_ui_locale'])) {
        return clmsSetUiLocale($_COOKIE['clms_ui_locale']);
    }

    return clmsSetUiLocale('en');
}

function clmsGetUiQueryParam(): ?string
{
    foreach (['ui_lang', 'lang'] as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }
        $value = trim((string) $_GET[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function clmsCurrentRequestPath(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/cargochina/';
}

function clmsCurrentUrlWithUiLocale(string $locale): string
{
    $uri = clmsCurrentRequestPath();
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/cargochina/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['ui_lang'] = clmsNormalizeUiLocale($locale);
    $queryString = http_build_query($query);
    return $path . ($queryString !== '' ? ('?' . $queryString) : '');
}

function clmsMaybeHandleUiLocaleSwitch(): void
{
    static $handled = false;
    if ($handled) {
        return;
    }
    $handled = true;

    $requested = clmsGetUiQueryParam();
    if ($requested === null) {
        clmsGetUiLocale();
        return;
    }

    clmsSetUiLocale($requested);

    $uri = clmsCurrentRequestPath();
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/cargochina/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    unset($query['ui_lang'], $query['lang']);
    $queryString = http_build_query($query);
    $target = $path . ($queryString !== '' ? ('?' . $queryString) : '');

    header('Location: ' . $target);
    exit;
}

function clmsTranslateText(string $text, ?string $locale = null): string
{
    $locale = clmsNormalizeUiLocale($locale ?? clmsGetUiLocale());
    if ($locale === 'en') {
        return $text;
    }

    $translations = clmsUiTranslations();
    return clmsTraditionalizeText(clmsResolveUiTranslation($text, $translations[$locale] ?? [], $locale), $locale);
}

function clmsTraditionalizeText(string $text, ?string $locale = null): string
{
    if (clmsNormalizeUiLocale($locale ?? clmsGetUiLocale()) !== 'zh-CN' || $text === '') {
        return $text;
    }

    static $map = null;
    if ($map === null) {
        $map = [
            '仪表盘' => '儀表板', '订单' => '訂單', '流程看板' => '流程看板', '发运' => '發運',
            '草稿' => '草稿', '创建' => '建立', '管理' => '管理', '系统' => '系統',
            '配置' => '設定', '诊断' => '診斷', '轨迹' => '軌跡', '审计' => '稽核',
            '日志' => '日誌', '基础资料' => '基礎資料', '资料' => '資料', '用户' => '使用者',
            '权限' => '權限', '角色' => '角色', '页面' => '頁面', '偏好设置' => '偏好設定',
            '设置' => '設定', '业务' => '業務', '规则' => '規則', '预设' => '預設',
            '状态' => '狀態', '队列' => '佇列', '项目' => '項目', '产品' => '產品',
            '商品' => '商品', '客户' => '客戶', '供应商' => '供應商', '员工' => '員工',
            '财务' => '財務', '费用' => '費用', '余额' => '餘額', '应收' => '應收',
            '应付' => '應付', '台账' => '臺帳', '预存款' => '預存款', '付款' => '付款',
            '账户' => '帳戶', '银行' => '銀行', '转账' => '轉帳', '链接' => '連結',
            '导出' => '匯出', '导入' => '匯入', '下载' => '下載', '上传' => '上傳',
            '搜索' => '搜尋', '筛选' => '篩選', '清除' => '清除', '应用' => '套用',
            '保存' => '儲存', '取消' => '取消', '关闭' => '關閉', '删除' => '刪除',
            '编辑' => '編輯', '添加' => '新增', '移除' => '移除', '确认' => '確認',
            '批准' => '核准', '提交' => '提交', '完成' => '完成', '查看' => '檢視',
            '打印' => '列印', '粘贴' => '貼上', '拍照' => '拍照', '选择' => '選擇',
            '输入' => '輸入', '编号' => '編號', '名称' => '名稱', '邮箱' => '電子郵箱', '电话' => '電話',
            '地址' => '地址', '备注' => '備註', '通知' => '通知', '提醒' => '提醒',
            '消息' => '訊息', '错误' => '錯誤', '网络' => '網路', '加载' => '載入',
            '刷新' => '重新整理', '检测' => '偵測', '差异' => '差異', '损坏' => '損壞',
            '证据' => '憑證', '凭证' => '憑證', '照片' => '照片', '图片' => '圖片',
            '仓库' => '倉庫', '库存' => '庫存', '收货' => '收貨', '入库' => '入庫',
            '测量' => '測量', '实际' => '實際', '重量' => '重量', '箱' => '箱',
            '纸箱' => '紙箱', '数量' => '數量', '单价' => '單價', '金额' => '金額',
            '总计' => '總計', '合计' => '合計', '总' => '總', '每箱件数' => '每箱件數',
            '件数' => '件數', '单' => '單', '币种' => '幣別', '货币' => '貨幣',
            '国家' => '國家', '目的地' => '目的地', '目的国家' => '目的國家',
            '运输' => '運輸', '运输代码' => '運輸代碼', '发货' => '發貨', '发件' => '寄件',
            '拼柜' => '併櫃', '集装箱' => '貨櫃', '分配' => '分配', '装船' => '裝船',
            '开船' => '開船', '到港' => '抵港', '离港' => '離港', '船名' => '船名',
            '预计' => '預計', '已' => '已', '未' => '未', '待' => '待', '可选' => '選填',
            '必须' => '必須', '需要' => '需要', '无法' => '無法', '请' => '請',
            '继续' => '繼續', '返回' => '返回', '成功' => '成功', '失败' => '失敗',
            '启用' => '啟用', '停用' => '停用', '普通货' => '普通貨', '常规货' => '常規貨',
            '仿货' => '仿貨', '复制' => '複製', '简体' => '簡體', '繁体' => '繁體',
            '语言' => '語言', '界面' => '介面', '说明' => '說明', '描述' => '描述',
            '处理' => '處理', '当前' => '目前', '显示' => '顯示', '隐藏' => '隱藏',
            '与' => '與', '价格' => '價格', '编码' => '編碼', '税费' => '稅費',
            '侧边栏' => '側邊欄', '合并' => '合併',
            '日历' => '日曆', '退出登录' => '登出', '超级管理员' => '超級管理員',
            '检视' => '檢視', '视图' => '檢視', '进入' => '進入', '记录' => '記錄',
            '数据' => '資料', '可见' => '可見', '计划' => '計畫', '重点' => '重點',
            '标记' => '標記', '箱数' => '箱數', '目前可见' => '目前可見',
            '申报' => '申報', '没有' => '沒有', '收紧' => '收緊', '紧急' => '緊急',
            '工厂' => '工廠', '明细' => '明細', '自动' => '自動', '相机' => '相機', '相册' => '相簿',
            '置顶' => '置頂', '仅' => '僅', '开始' => '開始', '结束' => '結束',
            '个' => '個', '识别' => '識別', '断' => '斷', '试' => '試',
            '库' => '庫', '户' => '戶', '应' => '應', '单' => '單', '订' => '訂',
            '仪' => '儀', '盘' => '盤', '页' => '頁', '设' => '設', '轨' => '軌',
            '迹' => '跡', '计' => '計', '统' => '統', '权' => '權', '员' => '員',
            '务' => '務', '费' => '費', '额' => '額', '账' => '帳', '资' => '資',
            '产' => '產', '导' => '匯', '载' => '載', '传' => '傳', '搜' => '搜',
            '选' => '選', '删' => '刪', '关' => '關', '闭' => '閉', '认' => '認',
            '证' => '證', '损' => '損', '坏' => '壞', '测' => '測', '际' => '際',
            '纸' => '紙', '数' => '數', '价' => '價', '总' => '總', '种' => '種',
            '国' => '國', '运' => '運', '发' => '發', '柜' => '櫃', '装' => '裝',
            '预计' => '預計', '预' => '預', '择' => '擇', '输' => '輸', '误' => '誤',
            '网' => '網', '络' => '路', '显' => '顯', '隐' => '隱', '优' => '優',
            '级' => '級', '换' => '換', '态' => '態', '约' => '約', '为' => '為',
            '这' => '這', '处' => '處', '进' => '進', '时' => '時', '间' => '間',
            '过' => '過', '后' => '後', '开' => '開', '码' => '碼', '汇' => '匯',
            '币' => '幣', '别' => '別', '览' => '覽', '写' => '寫', '称' => '稱',
            '条' => '條', '链' => '鏈', '结' => '結', '图' => '圖', '异' => '異',
            '实' => '實', '项' => '項', '态' => '態',
            '尽' => '儘', '并' => '並', '盖' => '蓋', '来' => '來', '适' => '適',
            '划' => '劃', '类' => '類', '该' => '該', '览' => '覽', '录' => '錄',
            '获' => '獲', '离' => '離', '达' => '達', '还' => '還', '对' => '對',
            '优' => '優', '复' => '複', '亲' => '親', '买' => '買', '卖' => '賣',
            '读' => '讀', '写' => '寫', '头' => '頭', '体' => '體', '点' => '點',
            '线' => '線', '维' => '維', '护' => '護', '审' => '審', '据' => '據',
            '处' => '處', '变' => '變', '签' => '簽', '页' => '頁', '见' => '見',
            '从' => '從', '临' => '臨', '层' => '層', '写' => '寫', '项' => '項',
            '邮' => '郵', '电' => '電', '话' => '話', '联' => '聯', '门' => '門',
            '软' => '軟', '质' => '質', '调' => '調', '属' => '屬', '错' => '錯',
            '拥' => '擁', '众' => '眾', '宁' => '寧', '历' => '曆', '标' => '標',
            '侧' => '側', '边' => '邊', '栏' => '欄', '货' => '貨', '则' => '則',
            '将' => '將', '会' => '會', '于' => '於', '园' => '園', '场' => '場',
            '厂' => '廠', '细' => '細', '长' => '長', '宽' => '寬', '动' => '動',
            '张' => '張', '机' => '機', '册' => '冊',
        ];
    }

    return strtr($text, $map);
}

function clmsNormalizeTranslationText(string $text): string
{
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function clmsTranslationPatternsForLocale(string $locale, array $localeTranslations): array
{
    static $patterns = [];
    if (isset($patterns[$locale])) {
        return $patterns[$locale];
    }

    $compiled = [];
    foreach (($localeTranslations['strings'] ?? []) as $source => $translated) {
        $normalizedSource = clmsNormalizeTranslationText((string) $source);
        if (!preg_match_all('/\{([A-Za-z0-9_]+)\}/', $normalizedSource, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $regex = '';
        $offset = 0;
        $placeholders = [];
        foreach ($matches[0] as $index => $match) {
            $token = $match[0];
            $position = $match[1];
            $regex .= preg_quote(substr($normalizedSource, $offset, $position - $offset), '/');
            $regex .= '(.+?)';
            $placeholders[] = $matches[1][$index][0];
            $offset = $position + strlen($token);
        }
        $regex .= preg_quote(substr($normalizedSource, $offset), '/');
        $compiled[] = [
            'regex' => '/^' . $regex . '$/u',
            'placeholders' => $placeholders,
            'translated' => (string) $translated,
        ];
    }

    $patterns[$locale] = $compiled;
    return $compiled;
}

function clmsResolveUiTranslation(string $text, array $localeTranslations, ?string $locale = null): string
{
    $normalized = clmsNormalizeTranslationText($text);
    $strings = $localeTranslations['strings'] ?? [];
    $statuses = $localeTranslations['statuses'] ?? [];

    foreach ([$text, $normalized] as $candidate) {
        if ($candidate !== '' && isset($strings[$candidate])) {
            return (string) $strings[$candidate];
        }
        if ($candidate !== '' && isset($statuses[$candidate])) {
            return (string) $statuses[$candidate];
        }
    }

    $locale = clmsNormalizeUiLocale($locale ?? clmsGetUiLocale());
    foreach (clmsTranslationPatternsForLocale($locale, $localeTranslations) as $pattern) {
        if (!preg_match($pattern['regex'], $normalized, $matches)) {
            continue;
        }
        $translated = $pattern['translated'];
        foreach ($pattern['placeholders'] as $index => $placeholder) {
            $translated = str_replace('{' . $placeholder . '}', $matches[$index + 1] ?? '', $translated);
        }
        return $translated;
    }

    return $text;
}

function clmsT(string $text, array $params = [], ?string $locale = null): string
{
    $translated = clmsTranslateText($text, $locale);
    if (!$params) {
        return $translated;
    }

    $replacements = [];
    foreach ($params as $key => $value) {
        $replacements['{' . $key . '}'] = (string) $value;
    }
    return strtr($translated, $replacements);
}

function clmsStatusLabel(string $status, ?string $locale = null): string
{
    $locale = clmsNormalizeUiLocale($locale ?? clmsGetUiLocale());
    $translations = clmsUiTranslations();
    if ($locale !== 'en' && isset($translations[$locale]['statuses'][$status])) {
        return clmsTraditionalizeText((string) $translations[$locale]['statuses'][$status], $locale);
    }
    return $status;
}

function clmsCountryDisplayName(?string $name, ?string $code = null, ?string $locale = null): string
{
    $name = trim((string) ($name ?? ''));
    $code = strtoupper(trim((string) ($code ?? '')));
    $locale = clmsNormalizeUiLocale($locale ?? clmsGetUiLocale());
    if ($locale === 'zh-CN' && $code !== '') {
        static $countryNames = null;
        if ($countryNames === null) {
            $file = __DIR__ . '/country_translations_zh_hant.php';
            $countryNames = file_exists($file) ? (require $file) : [];
        }
        if (!empty($countryNames[$code])) {
            return (string) $countryNames[$code];
        }
    }
    return $name;
}

function clmsCountryDisplayLabel(?string $name, ?string $code = null, ?string $locale = null): string
{
    $display = clmsCountryDisplayName($name, $code, $locale);
    $code = strtoupper(trim((string) ($code ?? '')));
    return trim($display . ($code !== '' ? ' (' . $code . ')' : ''));
}

function clmsGetClientTranslationPayload(): array
{
    $locale = clmsGetUiLocale();
    $translations = clmsUiTranslations();
    $strings = $translations[$locale]['strings'] ?? [];
    $statuses = $translations[$locale]['statuses'] ?? [];
    if ($locale !== 'en') {
        $strings = array_map(static fn($value) => clmsTraditionalizeText((string) $value, $locale), $strings);
        $statuses = array_map(static fn($value) => clmsTraditionalizeText((string) $value, $locale), $statuses);
    }
    return [
        'locale' => $locale,
        'strings' => $strings,
        'statuses' => $statuses,
    ];
}

if (!defined('CLMS_I18N_DISABLE_AUTO_SWITCH') || CLMS_I18N_DISABLE_AUTO_SWITCH !== true) {
    clmsMaybeHandleUiLocaleSwitch();
}
