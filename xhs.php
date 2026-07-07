<?php
// 小红书内容保存功能
// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 如果是AJAX请求，设置JSON header，否则设置HTML header
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_xiaohongshu') {
    ob_start();
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=UTF-8');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

// 从输入文本中提取和清理链接
function extractAndCleanUrl($input) {
    // 移除多余的空格和换行符
    $input = preg_replace('/\s+/', ' ', trim($input));
    
    // 匹配各种平台链接的正则表达式
    $urlPatterns = [
        // 小红书链接 - 支持各种格式，包括 /o/ 路径
        '/https?:\/\/www\.xiaohongshu\.com\/explore\/[a-zA-Z0-9]+/',
        '/https?:\/\/xhslink\.com\/[a-zA-Z0-9\/]+[a-zA-Z0-9]+(?:\?[^\s\)\]\}\"\'\,]*)?/',  // 支持 /o/ 路径，如 /o/AiCioE08yX
        '/https?:\/\/xhslink\.com\/[a-zA-Z0-9]+/',
    ];
    
    // 尝试匹配各种链接格式
    foreach ($urlPatterns as $pattern) {
        if (preg_match($pattern, $input, $matches)) {
            return $matches[0]; // 返回第一个匹配的链接
        }
    }
    
    // 如果没有找到标准格式的链接，尝试查找任何包含http的文本
    // 改进正则表达式，排除中文标点符号和常见标点，但保留URL字符
    if (preg_match('/https?:\/\/[^\s\)\]\}\"\'\,\！\？\。\，\；\：\、\…\（\）\【\】\《\》\「\」\『\』]+/u', $input, $matches)) {
        $url = $matches[0];
        // 清理URL，移除可能的额外字符，但保留必要的URL字符
        // 特别处理小红书链接，保留 /o/ 路径
        if (strpos($url, 'xhslink.com') !== false) {
            // 对于小红书链接，保留路径部分，移除所有非URL字符
            $url = preg_replace('/[^\w\-\.\/\?\=\&\%\#\:\+]/', '', $url);
        } else {
            $url = preg_replace('/[^\w\-\.\/\?\=\&\%\#\:\+]/', '', $url);
        }
        // 确保URL以/结尾时正确处理（但保留必要的路径分隔符）
        if (substr($url, -1) === '/' && !preg_match('/\/[a-zA-Z0-9]+\/$/', $url)) {
            $url = substr($url, 0, -1);
        }
        return $url;
    }
    
    // 如果输入本身就是URL格式，直接返回（清理后）
    if (strpos($input, 'http://') === 0 || strpos($input, 'https://') === 0) {
        // 清理URL末尾可能的中文标点符号
        $url = preg_replace('/[^\w\-\.\/\?\=\&\%\#\:\+]+$/u', '', $input);
        return $url;
    }
    
    return null; // 如果无法提取，返回 null
}

// 调用小红书解析API
function parseXiaohongshuUrl($url) {
    $apiUrl = 'https://xcx.kmzp0871.com/11111/jx_video.php?url=' . urlencode($url);
    
    // 重试机制：最多重试3次
    $maxRetries = 3;
    $retryDelay = 2; // 秒
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 增加超时时间
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        // 强制使用 HTTP/1.1，避免 HTTP/2 连接问题
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        // 禁用 HTTP/2
        if (defined('CURLOPT_HTTP09_ALLOWED')) {
            curl_setopt($ch, CURLOPT_HTTP09_ALLOWED, false);
        }
        // 添加连接稳定性选项
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        if (defined('CURLOPT_TCP_KEEPIDLE')) {
            curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 10);
        }
        if (defined('CURLOPT_TCP_KEEPINTVL')) {
            curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 1);
        }
        // DNS 解析优化
        curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 300);
        // 启用压缩
        curl_setopt($ch, CURLOPT_ENCODING, '');
        // 添加请求头，模拟真实浏览器
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Expect:',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
            'Connection: keep-alive'
        ]);
        // 设置传输速度限制，避免被服务器拒绝
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1000);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        // 检查是否有错误
        if ($error) {
            // 如果是最后一次尝试，返回错误
            if ($attempt >= $maxRetries) {
                return [
                    'success' => false,
                    'error' => 'API请求失败：' . $error . ' (已重试' . $maxRetries . '次)'
                ];
            }
            // 否则等待后重试
            sleep($retryDelay);
            continue;
        }
        
        // 检查响应是否为空
        if (empty($response)) {
            // 如果是最后一次尝试，返回错误
            if ($attempt >= $maxRetries) {
                return [
                    'success' => false,
                    'error' => 'API返回空响应，服务器可能暂时无法响应 (已重试' . $maxRetries . '次，HTTP状态码：' . $httpCode . ')'
                ];
            }
            // 否则等待后重试
            sleep($retryDelay);
            continue;
        }
        
        // 检查HTTP状态码
        if ($httpCode !== 200) {
            // 如果是最后一次尝试，返回错误
            if ($attempt >= $maxRetries) {
                return [
                    'success' => false,
                    'error' => 'API请求失败，HTTP状态码：' . $httpCode . ' (已重试' . $maxRetries . '次)'
                ];
            }
            // 对于5xx错误，等待后重试
            if ($httpCode >= 500 && $httpCode < 600) {
                sleep($retryDelay);
                continue;
            } else {
                // 对于其他错误，直接返回
                return [
                    'success' => false,
                    'error' => 'API请求失败，HTTP状态码：' . $httpCode
                ];
            }
        }
        
        // 解析JSON响应
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 如果是最后一次尝试，返回错误
            if ($attempt >= $maxRetries) {
                return [
                    'success' => false,
                    'error' => 'API返回数据格式错误：' . json_last_error_msg() . ' (响应内容：' . substr($response, 0, 200) . '...)'
                ];
            }
            // 否则等待后重试
            sleep($retryDelay);
            continue;
        }
        
        // 新API返回格式：code=200表示成功，code=-1或其他表示失败
        if (isset($result['code']) && $result['code'] == 200 && isset($result['data'])) {
            return [
                'success' => true,
                'data' => $result['data']
            ];
        } else {
            // 优先使用error字段，其次使用msg字段
            $errorMsg = isset($result['error']) ? $result['error'] : (isset($result['msg']) ? $result['msg'] : '解析失败');
            return [
                'success' => false,
                'error' => $errorMsg
            ];
        }
    }
    
    // 理论上不会到达这里，但为了安全起见
    return [
        'success' => false,
        'error' => 'API请求失败：未知错误'
    ];
}

// 下载图片到本地
function downloadImage($imageUrl, $saveDir) {
    try {
        // 确保目录存在
        if (!is_dir($saveDir)) {
            if (!mkdir($saveDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => '无法创建图片保存目录'
                ];
            }
        }
        
        // 获取图片扩展名
        $urlParts = parse_url($imageUrl);
        $path = isset($urlParts['path']) ? $urlParts['path'] : '';
        $extension = '';
        
        // 尝试从URL中获取扩展名
        if (preg_match('/\.(jpg|jpeg|png|gif|webp)/i', $path, $matches)) {
            $extension = '.' . strtolower($matches[1]);
        } else {
            // 默认使用jpg
            $extension = '.jpg';
        }
        
        // 生成唯一文件名
        $filename = 'xhs_' . date('Ymd_His') . '_' . uniqid() . $extension;
        $filepath = $saveDir . DIRECTORY_SEPARATOR . $filename;
        
        // 下载图片
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        // 强制使用 HTTP/1.1，避免 HTTP/2 连接问题
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        // 启用压缩
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => '下载图片失败：' . $error
            ];
        }
        
        if ($httpCode !== 200 || empty($imageData)) {
            return [
                'success' => false,
                'error' => '下载图片失败，HTTP状态码：' . $httpCode
            ];
        }
        
        // 保存图片
        if (file_put_contents($filepath, $imageData) === false) {
            return [
                'success' => false,
                'error' => '保存图片失败'
            ];
        }
        
        @chmod($filepath, 0644);
        
        // 获取主机名
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $url = 'http://' . $host . '/uploads/xiaohongshu/' . $filename;
        
        return [
            'success' => true,
            'url' => $url,
            'filepath' => $filepath,
            'filename' => $filename
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => '下载图片时发生错误：' . $e->getMessage()
        ];
    }
}

// 生成小红书文章文件名
function generateXHSArticleFilename($title) {
    $timestamp = date('Ymd_His');
    
    // 移除 Windows 文件系统不允许的字符
    $cleanTitle = preg_replace('/[<>:"\/\\\|?*\x00-\x1F]/', '', $title);
    $cleanTitle = str_replace(' ', '_', $cleanTitle);
    $cleanTitle = preg_replace('/[^a-zA-Z0-9\u4e00-\u9fa5_-]/', '', $cleanTitle);
    $cleanTitle = preg_replace('/_+/', '_', $cleanTitle);
    $cleanTitle = trim($cleanTitle, '_-');
    $cleanTitle = mb_substr($cleanTitle, 0, 30);
    
    if (empty($cleanTitle)) {
        $cleanTitle = 'xiaohongshu_article';
    }
    
    $filename = 'xhs_' . $cleanTitle . '_' . $timestamp . '.html';
    
    // 检查文件是否已存在
    $scriptDir = dirname(__FILE__);
    $articlesDir = $scriptDir . DIRECTORY_SEPARATOR . 'articles';
    $counter = 1;
    while (file_exists($articlesDir . DIRECTORY_SEPARATOR . $filename)) {
        $filename = 'xhs_' . $cleanTitle . '_' . $timestamp . '_' . $counter . '.html';
        $counter++;
    }
    
    return $filename;
}

// 格式化小红书文章内容
function formatXHSArticleContent($title, $images, $author = '', $desc = '') {
    // 小红书笔记风格布局：顶部作者信息 -> 图片轮播 -> 描述和标签 -> 交互按钮
    $content = '<div class="xhs-note">';
    
    // 顶部：作者信息
    $content .= '<div class="xhs-note-header">';
    if (!empty($author)) {
        $content .= '<div class="xhs-author-card">';
        $content .= '<div class="xhs-author-avatar">👤</div>';
        $content .= '<div class="xhs-author-info">';
        $content .= '<div class="xhs-author-name">' . htmlspecialchars($author, ENT_QUOTES, 'UTF-8') . '</div>';
        $content .= '</div>';
        $content .= '</div>';
    }
    $content .= '</div>';
    
    // 中间：图片展示区域（轮播样式）
    if (!empty($images) && is_array($images)) {
        $content .= '<div class="xhs-images-carousel">';
        $imageCount = count($images);
        foreach ($images as $index => $imageUrl) {
            $content .= '<div class="xhs-image-slide' . ($index === 0 ? ' active' : '') . '">';
            $content .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="小红书图片" class="xhs-image">';
            $content .= '</div>';
        }
        // 图片指示器
        if ($imageCount > 1) {
            $content .= '<div class="xhs-image-indicator">';
            $content .= '<span id="currentImage">1</span> / <span id="totalImages">' . $imageCount . '</span>';
            $content .= '</div>';
            // 左右箭头
            $content .= '<div class="xhs-carousel-nav">';
            $content .= '<button class="xhs-nav-btn xhs-nav-prev" onclick="changeImage(-1)">‹</button>';
            $content .= '<button class="xhs-nav-btn xhs-nav-next" onclick="changeImage(1)">›</button>';
            $content .= '</div>';
        }
        $content .= '</div>';
    }
    
    // 底部：描述、标题和标签
    $content .= '<div class="xhs-note-content">';
    
    // 从描述中提取标签（# 开头的文本）
    $tags = [];
    $cleanDesc = $desc;
    if (!empty($desc)) {
        // 匹配所有 # 开头的标签
        if (preg_match_all('/#([^\s#]+)/u', $desc, $tagMatches)) {
            $tags = array_unique($tagMatches[1]);
            // 从描述中移除标签，只保留描述文本
            $cleanDesc = preg_replace('/#([^\s#]+)/u', '', $desc);
                     

            $cleanDesc = trim($cleanDesc);
            $content .= '';
        }
    }
    
    // 描述信息（如果有，优先显示描述）
    if (!empty($cleanDesc)) {
        $descHtml = str_replace(["\n", "\r\n"], ['<br>', '<br>'], htmlspecialchars($cleanDesc, ENT_QUOTES, 'UTF-8'));
        $content .= '<div class="xhs-note-desc">' . $descHtml . '</div>';
    } elseif (!empty($title)) {
        // 如果没有描述，使用标题作为描述
        $titleHtml = str_replace(["\n", "\t"], ['<br>', '&nbsp;&nbsp;&nbsp;&nbsp;'], htmlspecialchars($title));
        $content .= '<div class="xhs-note-desc">' . $titleHtml . '</div>';
    }
    
    // 标签（优先显示从描述中提取的标签）
    if (!empty($tags)) {
        $content .= '<div class="xhs-tags">';
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (!empty($tag)) {
                $content .= '<span class="xhs-tag-item"># ' . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '</span>';
            }
        }
        $content .= '</div>';
    } else {
        // 如果没有提取到标签，显示默认标签
        $content .= '<div class="xhs-tags">';
        $content .= '<span class="xhs-tag-item"># 小红书</span>';
        $content .= '<span class="xhs-tag-item"># 去水印</span>';
        $content .= '</div>';
    }
    
    $content .= '</div>';
    
    // 微信提示（放在最底部）
    $content .= '<div class="xhs-wechat-tip">';
    $content .= '<div class="wechat-tip-content">';
    $content .= '<p>💡 微信搜索"小青去水印"，一键免费去水印</p>';
    $content .= '</div>';
    $content .= '</div>';
    
    $content .= '</div>'; // 结束 xhs-note
    
    return $content;
}

// 获取文章模板（复用write_article.php的模板）
function getXHSArticleTemplate() {
    return <<<'TEMPLATE'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{TITLE}} -小青去水印</title>
    <meta name="description" content="{{DESCRIPTION}}">
    <meta name="keywords" content="{{KEYWORDS}}">
    <meta name="author" content="{{AUTHOR}}">
    
    <!-- Open Graph 标签 -->
    <meta property="og:title" content="{{TITLE}}">
    <meta property="og:description" content="{{DESCRIPTION}}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{PAGE_URL}}">
    
    <!-- Twitter Card 标签 -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{TITLE}}">
    <meta name="twitter:description" content="{{DESCRIPTION}}">
    
    <!-- 结构化数据 -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": "{{TITLE}}",
        "description": "{{DESCRIPTION}}",
        "author": {
            "@type": "Person",
            "name": "{{AUTHOR}}"
        },
        "publisher": {
            "@type": "Organization",
            "name": "{{SITE_NAME}}"
        },
        "datePublished": "{{PUBLISH_DATE}}"
    }
    </script>
    
    <link rel="canonical" href="{{PAGE_URL}}">
    <link rel="stylesheet" href="../style.css">
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📝</text></svg>">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
            line-height: 1.6;
            padding: 0;
        }
        
        .container {
            max-width: 414px;
            margin: 0 auto;
            background: white;
            min-height: 100vh;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        /* 小红书笔记样式 */
        .xhs-note {
            width: 100%;
            background: white;
        }
        
        /* 作者信息卡片 */
        .xhs-note-header {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .xhs-author-card {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .xhs-author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ff2442 0%, #ff6b9d 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .xhs-author-info {
            flex: 1;
        }
        
        .xhs-author-name {
            font-size: 15px;
            font-weight: 600;
            color: #333;
        }
        
        .xhs-follow-btn {
            padding: 6px 20px;
            background: #ff2442;
            color: white;
            border-radius: 20px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }
        
        .xhs-follow-btn:hover {
            background: #ff4d6d;
        }
        
        /* 图片轮播 */
        .xhs-images-carousel {
            position: relative;
            width: 100%;
            overflow: hidden;
            background: #000;
        }
        
        .xhs-image-slide {
            display: none;
            width: 100%;
        }
        
        .xhs-image-slide.active {
            display: block;
        }
        
        .xhs-image {
            width: 100%;
            height: auto;
            display: block;
            object-fit: contain;
            background: #000;
        }
        
        .xhs-image-indicator {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            z-index: 10;
        }
        
        .xhs-carousel-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 100%;
            display: flex;
            justify-content: space-between;
            padding: 0 12px;
            z-index: 10;
        }
        
        .xhs-nav-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            font-size: 24px;
            color: #333;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .xhs-nav-btn:hover {
            background: white;
            transform: scale(1.1);
        }
        
        /* 笔记内容 */
        .xhs-note-content {
            padding: 16px;
        }
        
        .xhs-note-desc {
            font-size: 15px;
            color: #333;
            line-height: 1.8;
            margin-bottom: 12px;
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        
        .xhs-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        .xhs-tag-item {
            display: inline-block;
            color: #ff2442;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }
        
        .xhs-tag-item:hover {
            opacity: 0.8;
        }
        
        /* 交互按钮栏 */
        .xhs-interaction-bar {
            display: flex;
            align-items: center;
            gap: 24px;
            padding: 12px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .xhs-interaction-item {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .xhs-interaction-item:active {
            transform: scale(0.95);
        }
        
        .xhs-icon {
            font-size: 24px;
        }
        
        .xhs-count {
            font-size: 14px;
            color: #666;
        }
        
        /* 微信提示 */
        .xhs-wechat-tip {
            padding: 16px;
            background: #fafafa;
        }
        
        .wechat-tip-content {
            text-align: center;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            color: white;
        }
        
        .wechat-tip-content p {
            margin: 0;
            font-size: 14px;
        }
        
        /* 返回按钮 */
        .back-btn-container {
            padding: 16px;
            text-align: center;
            background: white;
            border-top: 1px solid #f0f0f0;
        }
        
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: background 0.3s ease;
            font-size: 14px;
            margin: 0 5px;
        }
        
        .back-btn:hover {
            background: #5a6fd8;
        }
        
        @media (max-width: 768px) {
            .container {
                max-width: 100%;
                box-shadow: none;
            }
        }
        
        @media (min-width: 415px) {
            body {
                padding: 20px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        {{CONTENT}}
        
        <div class="back-btn-container">
            <a href="https://wxaurl.cn/1OmFmbue8if" class="back-btn" target="_blank">🎬 去水印</a>
            <a href="../index.php" class="back-btn">返回首页</a>
        </div>
    </div>
    
    <script>
        let currentImageIndex = 0;
        const totalImages = {{IMAGE_COUNT}};
        
        function changeImage(direction) {
            if (totalImages <= 1) return;
            
            const slides = document.querySelectorAll('.xhs-image-slide');
            const indicator = document.getElementById('currentImage');
            
            slides[currentImageIndex].classList.remove('active');
            
            currentImageIndex += direction;
            
            if (currentImageIndex < 0) {
                currentImageIndex = totalImages - 1;
            } else if (currentImageIndex >= totalImages) {
                currentImageIndex = 0;
            }
            
            slides[currentImageIndex].classList.add('active');
            
            if (indicator) {
                indicator.textContent = currentImageIndex + 1;
            }
        }
        
        // 触摸滑动支持
        let touchStartX = 0;
        let touchEndX = 0;
        
        const carousel = document.querySelector('.xhs-images-carousel');
        if (carousel && totalImages > 1) {
            carousel.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
            });
            
            carousel.addEventListener('touchend', function(e) {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipe();
            });
            
            function handleSwipe() {
                if (touchEndX < touchStartX - 50) {
                    changeImage(1); // 向左滑动，下一张
                }
                if (touchEndX > touchStartX + 50) {
                    changeImage(-1); // 向右滑动，上一张
                }
            }
        }
    </script>
<script>
(function(){
var el = document.createElement("script");
el.src = "https://lf1-cdn-tos.bytegoofy.com/goofy/ttzz/push.js?d2580c415bbec5bfd4988706bb125f06433f0f698fb5720129bf137c4d72722bf5b74c6b63cc8c01e9ff6284c564f2da3d72cd14f8a76432df3935ab77ec54f830517b3cb210f7fd334f50ccb772134a";
el.id = "ttzz";
var s = document.getElementsByTagName("script")[0];
s.parentNode.insertBefore(el, s);
})(window)
</script>
</body>
</html>
TEMPLATE;
}

// 保存小红书文章
function saveXHSArticle($url) {
    try {
        // 1. 解析小红书链接
        $parseResult = parseXiaohongshuUrl($url);
        if (!$parseResult['success']) {
            return [
                'success' => false,
                'error' => $parseResult['error']
            ];
        }
        
        $data = $parseResult['data'];
        $title = isset($data['title']) ? trim($data['title']) : '小红书内容';
        $images = isset($data['images']) && is_array($data['images']) ? $data['images'] : [];
        $author = isset($data['author']) ? trim($data['author']) : '';
        $desc = isset($data['desc']) ? trim($data['desc']) : '';
        
        // 如果标题为空，使用默认标题
        if (empty($title)) {
            $title = '小红书内容_' . date('Ymd_His');
        }
        
        // 2. 下载图片到本地
        $scriptDir = dirname(__FILE__);
        $imageSaveDir = $scriptDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'xiaohongshu';
        
        // 确保图片保存目录存在
        if (!is_dir($imageSaveDir)) {
            if (!mkdir($imageSaveDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => '无法创建图片保存目录'
                ];
            }
        }
        
        $localImages = [];
        $downloadErrors = [];
        
        // 下载所有图片
        foreach ($images as $index => $imageUrl) {
            $downloadResult = downloadImage($imageUrl, $imageSaveDir);
            if ($downloadResult['success']) {
                $localImages[] = $downloadResult['url'];
            } else {
                $downloadErrors[] = '图片 ' . ($index + 1) . ' 下载失败：' . $downloadResult['error'];
            }
        }
        
        // 如果所有图片都下载失败，仍然保存文章（可能只有文本）
        if (empty($localImages) && !empty($downloadErrors) && !empty($images)) {
            // 记录错误但不阻止保存
            error_log('小红书图片下载失败：' . implode('; ', $downloadErrors));
        }
        
        // 3. 生成文章内容
        $content = formatXHSArticleContent($title, $localImages, $author, $desc);
        
        // 4. 保存文章
        $scriptDir = dirname(__FILE__);
        $articlesDir = $scriptDir . DIRECTORY_SEPARATOR . 'articles';
        
        // 确保articles目录存在
        if (!is_dir($articlesDir)) {
            if (!mkdir($articlesDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => '无法创建articles目录'
                ];
            }
        }
        
        // 检查目录是否可写
        if (!is_writable($articlesDir)) {
            return [
                'success' => false,
                'error' => 'articles目录不可写，请检查目录权限'
            ];
        }
        
        // 生成文件名
        $filename = generateXHSArticleFilename($title);
        $filepath = $articlesDir . DIRECTORY_SEPARATOR . $filename;
        
        // 读取文章模板
        $template = getXHSArticleTemplate();
        if (empty($template)) {
            return [
                'success' => false,
                'error' => '无法读取文章模板'
            ];
        }
        
        // 获取主机名
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        
        // 准备替换数据
        // 格式化日期为小红书风格：2025年6月24日
        $publishDate = date('Y年n月j日');
        
        // 使用描述作为 meta description，如果没有描述则使用标题
        $metaDescription = !empty($desc) ? $desc : $title;
        $metaDescription = mb_substr(strip_tags($metaDescription), 0, 150);
        
        // 使用作者作为 meta author，如果没有作者则使用默认值
        $metaAuthor = !empty($author) ? $author : '小青去水印';
        
        // 获取图片数量
        $imageCount = count($localImages);
        
        $replacements = [
            '{{TITLE}}' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            '{{CONTENT}}' => $content,
            '{{DESCRIPTION}}' => htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8'),
            '{{KEYWORDS}}' => htmlspecialchars($title . ',小红书,内容分享', ENT_QUOTES, 'UTF-8'),
            '{{AUTHOR}}' => htmlspecialchars($metaAuthor, ENT_QUOTES, 'UTF-8'),
            '{{PUBLISH_DATE}}' => $publishDate,
            '{{PAGE_URL}}' => 'http://' . $host . '/articles/' . $filename,
            '{{SITE_NAME}}' => '视频去水印工具',
            '{{IMAGE_COUNT}}' => $imageCount
        ];
        
        // 替换模板内容
        $htmlContent = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // 写入文件
        $writeResult = @file_put_contents($filepath, $htmlContent);
        if ($writeResult === false) {
            $error = error_get_last();
            return [
                'success' => false,
                'error' => '无法写入文章文件：' . ($error ? $error['message'] : '未知错误')
            ];
        }
        
        // 尝试设置文件权限
        @chmod($filepath, 0644);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'url' => 'http://' . $host . '/articles/' . $filename,
            'title' => $title,
            'author' => $author,
            'desc' => $desc,
            'images_count' => count($localImages),
            'images' => $localImages,
            'download_errors' => $downloadErrors
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => '保存文章时发生错误：' . $e->getMessage()
        ];
    } catch (Error $e) {
        return [
            'success' => false,
            'error' => '保存文章时发生错误：' . $e->getMessage()
        ];
    }
}

// 推送URL到百度智能小程序
function pushToBaidu($htmlUrl, $cookie = '') {
    try {
        $apiUrl = 'https://smartprogram.baidu.com/smp/mapi/promotion/page/consistency/commit';
        
        // 准备JSON数据
        $data = [
            'appId' => '103728877',
            'emailAddr' => '863867122@qq.com',
            'app_id' => '103728877',
            'email_addr' => '7638671122@qq.com',
            'items' => [
                [
                    'web_page' => $htmlUrl,
                    'app_page' => '/pages/index/index',
                    'webPage' => $htmlUrl,
                    'appPage' => '/pages/index/index'
                ]
            ]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json;charset=UTF-8',
            'Cookie: ' . $cookie,
            'Expect:'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        // 强制使用 HTTP/1.1，避免 HTTP/2 连接问题
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => '推送请求失败：' . $error
            ];
        }
        
        // 尝试解析响应
        $result = json_decode($response, true);
        
        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $result ?: $response,
            'message' => $httpCode === 200 ? '推送成功' : '推送失败，HTTP状态码：' . $httpCode
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => '推送时发生错误：' . $e->getMessage()
        ];
    }
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_xiaohongshu') {
    ob_clean();
    
    try {
        if (!isset($_POST['url']) || empty(trim($_POST['url']))) {
            echo json_encode([
                'success' => false,
                'error' => '请输入小红书链接'
            ]);
            exit;
        }
        
        $input = trim($_POST['url']);
        
        // 从输入文本中提取链接
        $url = extractAndCleanUrl($input);
        
        if (!$url) {
            echo json_encode([
                'success' => false,
                'error' => '未找到有效的小红书链接。请确保输入内容包含完整的小红书链接（支持 xiaohongshu.com 或 xhslink.com）。'
            ]);
            exit;
        }
        
        // 验证是否为小红书链接
        if (strpos($url, 'xiaohongshu.com') === false && strpos($url, 'xhslink.com') === false) {
            echo json_encode([
                'success' => false,
                'error' => '请输入有效的小红书链接（支持 xiaohongshu.com 或 xhslink.com）'
            ]);
            exit;
        }
        
        // 获取Cookie（可选，用于推送URL到百度）
        $cookie = isset($_POST['cookie']) ? trim($_POST['cookie']) : '';
        
        // 保存文章
        $result = saveXHSArticle($url);
        
        if ($result['success']) {
            $response = [
                'success' => true,
                'title' => $result['title'],
                'author' => isset($result['author']) ? $result['author'] : '',
                'desc' => isset($result['desc']) ? $result['desc'] : '',
                'html_file' => [
                    'filename' => $result['filename'],
                    'url' => $result['url']
                ],
                'images_count' => $result['images_count'],
                'images' => isset($result['images']) ? $result['images'] : [],
                'message' => '小红书内容保存成功！'
            ];
            
            // 如果有下载错误，添加到响应中
            if (!empty($result['download_errors'])) {
                $response['warnings'] = $result['download_errors'];
            }
            
            // 如果提供了Cookie，静默推送到百度（不显示给用户）
            if (!empty($cookie) && !empty($result['url'])) {
                $pushResult = pushToBaidu($result['url'], $cookie);
                // 推送结果不添加到响应中，保持静默
                // 只在后台记录日志（可选）
                if (!$pushResult['success']) {
                    error_log('百度推送失败：' . ($pushResult['error'] ?? '未知错误'));
                }
            }
            
            echo json_encode($response);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
        }
    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'error' => '处理请求时发生错误：' . $e->getMessage()
        ]);
    } catch (Error $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'error' => '处理请求时发生错误：' . $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>保存小红书内容 - 视频去水印工具</title>
    <meta name="description" content="保存小红书内容到网站，自动下载无水印图片">
    <meta name="keywords" content="小红书,内容保存,图片下载">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📕</text></svg>">
    
    <style>
        .xhs-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .xhs-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .xhs-title {
            font-size: 2.5rem;
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .xhs-subtitle {
            color: #6c757d;
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .form-label .required {
            color: #dc3545;
            margin-left: 3px;
        }
        
        .url-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }
        
        .url-input:focus {
            outline: none;
            border-color: #ff2442;
        }
        
        .save-btn {
            width: 100%;
            background: linear-gradient(135deg, #ff2442 0%, #ff6b6b 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .save-btn:hover {
            transform: translateY(-2px);
        }
        
        .save-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .tips {
            background: #fff5f5;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #ff2442;
            margin-bottom: 30px;
        }
        
        .tips h4 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .tips ul {
            color: #6c757d;
            margin: 0;
            padding-left: 20px;
        }
        
        .tips li {
            margin-bottom: 5px;
        }
        
        .result-section {
            display: none;
            margin-top: 30px;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
        }
        
        .result-section.success {
            background: #d4edda;
            border-color: #c3e6cb;
        }
        
        .result-section.error {
            background: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        /* 左右布局样式 */
        .result-content-layout {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .images-gallery {
            flex: 0 0 45%;
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .gallery-title {
            font-size: 1.1rem;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .images-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            max-height: 600px;
            overflow-y: auto;
            padding: 5px;
        }
        
        .images-grid::-webkit-scrollbar {
            width: 6px;
        }
        
        .images-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .images-grid::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        .images-grid::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .images-grid .image-item {
            position: relative;
            width: 100%;
            padding-top: 100%;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            background: #f5f5f5;
        }
        
        .images-grid .image-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        }
        
        .images-grid .image-item img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .images-grid .image-item:hover img {
            transform: scale(1.1);
        }
        
        .article-content-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .article-info-container {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .article-title-display {
            font-size: 1.3rem;
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 15px;
            line-height: 1.5;
            word-break: break-word;
        }
        
        .article-author-display {
            font-size: 0.95rem;
            color: #666;
            margin-bottom: 12px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .article-author-display::before {
            content: '👤';
            font-size: 1rem;
        }
        
        .article-desc-display {
            font-size: 0.95rem;
            color: #555;
            line-height: 1.7;
            margin-bottom: 15px;
            padding: 12px;
            background: #fafafa;
            border-radius: 6px;
            border-left: 3px solid #667eea;
            word-break: break-word;
            white-space: pre-wrap;
        }
        
        .article-meta-info {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .article-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #6c757d;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .result-content-layout {
                flex-direction: column;
            }
            
            .images-gallery {
                flex: 1;
            }
            
            .images-grid {
                grid-template-columns: repeat(2, 1fr);
                max-height: 400px;
            }
        }
        
        .result-title {
            font-size: 1.3rem;
            color: #2c3e50;
            margin: 0;
        }
        
        .view-article-btn {
            background: #28a745;
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            transition: background 0.3s ease;
        }
        
        .view-article-btn:hover {
            background: #218838;
        }
        
        /* HTML文件信息卡片样式 */
        .html-file-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border: 1px solid #bbdefb;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .html-info-header h4 {
            color: #1976d2;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .html-info-content {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .html-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .html-info-item .info-label {
            font-weight: 600;
            color: #424242;
            min-width: 80px;
        }
        
        .html-info-item .info-value {
            color: #1976d2;
            font-family: 'Courier New', monospace;
            background: rgba(25, 118, 210, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .html-info-item .info-value.success {
            color: #4caf50;
            background: rgba(76, 175, 80, 0.1);
        }
        
        .html-link {
            color: #1976d2;
            text-decoration: none;
            word-break: break-all;
            background: rgba(25, 118, 210, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .html-link:hover {
            background: rgba(25, 118, 210, 0.2);
            text-decoration: underline;
        }
        
        .html-info-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .view-html-btn, .copy-html-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .view-html-btn {
            background: #1976d2;
            color: white;
        }
        
        .view-html-btn:hover {
            background: #1565c0;
            transform: translateY(-1px);
        }
        
        .copy-html-btn {
            background: #4caf50;
            color: white;
        }
        
        .copy-html-btn:hover {
            background: #45a049;
            transform: translateY(-1px);
        }
        
        /* 文章信息容器样式已在上面定义 */
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }
        
        .loading.show {
            display: block;
        }
        
        @media (max-width: 768px) {
            .xhs-container {
                margin: 20px;
                padding: 20px;
            }
            
            .xhs-title {
                font-size: 2rem;
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="xhs-container">
            <div class="xhs-header">
                <h1 class="xhs-title">
                    <span>📕</span>
                    <span>保存小红书内容</span>
                </h1>
                <p class="xhs-subtitle">输入小红书链接，自动解析并保存到网站</p>
            </div>
            
            <div class="tips">
                <h4>💡 使用说明</h4>
                <ul>
                    <li>输入小红书笔记的完整链接</li>
                    <li>系统会自动解析内容并下载无水印图片</li>
                    <li>内容将自动发布到网站</li>
                    <li>支持 <code>xiaohongshu.com</code> 和 <code>xhslink.com</code> 格式的链接</li>
                </ul>
            </div>
            
            <form id="xhsForm">
                <div class="form-group">
                    <label class="form-label" for="xhsUrl">
                        小红书链接 <span class="required">*</span>
                    </label>
                    <input type="text" id="xhsUrl" class="url-input" placeholder="请输入小红书笔记链接，例如：https://www.xiaohongshu.com/explore/..." required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="baiduCookie">
                        百度Cookie（用于推送，可选）
                    </label>
                    <input type="text" id="baiduCookie" name="cookie" class="url-input" placeholder="请输入百度Cookie，用于推送URL到百度智能小程序">
                </div>
                
                <button type="submit" id="saveBtn" class="save-btn">
                    <span class="btn-text">保存到网站</span>
                    <span class="btn-loading" style="display: none;">处理中...</span>
                </button>
            </form>
            
            <div class="loading" id="loading">
                <p>正在解析小红书内容，请稍候...</p>
                <p style="font-size: 14px; color: #6c757d; margin-top: 10px;">正在下载图片，这可能需要一些时间</p>
            </div>
            
            <div id="resultSection" class="result-section" style="display: none;">
                <div id="successDiv" style="display: none;">
                    <div class="result-header">
                        <h3 class="result-title">✅ 保存成功！</h3>
                    </div>
                    
                    <div class="result-content-layout">
                        <!-- 左侧图集 -->
                        <div class="images-gallery">
                            <h4 class="gallery-title">📷 图片集</h4>
                            <div id="imagesGrid" class="images-grid"></div>
                        </div>
                        
                        <!-- 右侧内容 -->
                        <div class="article-content-panel">
                            <div class="article-info-container">
                                <div class="article-title-display" id="articleTitleDisplay"></div>
                                <div class="article-author-display" id="articleAuthorDisplay" style="display: none;"></div>
                                <div class="article-desc-display" id="articleDescDisplay" style="display: none;"></div>
                                <div class="article-meta-info">
                                    <div class="article-meta-item">
                                        <span>📷</span>
                                        <span id="imagesCountDisplay"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="htmlFileInfo" class="html-file-info" style="display: none;">
                                <div class="html-info-header">
                                    <h4>💾 HTML文件已静默保存</h4>
                                </div>
                                <div class="html-info-content">
                                    <div class="html-info-item">
                                        <span class="info-label">文件名:</span>
                                        <span class="info-value" id="htmlFileName"></span>
                                    </div>
                                    <div class="html-info-item">
                                        <span class="info-label">状态:</span>
                                        <span class="info-value success">✅ 保存成功</span>
                                    </div>
                                    <div class="html-info-item">
                                        <span class="info-label">链接:</span>
                                        <a href="#" id="htmlFileUrl" class="html-link" target="_blank"></a>
                                    </div>
                                </div>
                                <div class="html-info-actions">
                                    <a href="#" id="viewHtmlBtn" class="view-html-btn" target="_blank">
                                        <span>👁️</span>
                                        <span>查看文章</span>
                                    </a>
                                    <button type="button" id="copyHtmlBtn" class="copy-html-btn">
                                        <span>📋</span>
                                        <span>复制链接</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="errorDiv" style="display: none;">
                    <h3 style="color: #721c24; margin-bottom: 10px;">❌ 保存失败</h3>
                    <p id="errorMessage" style="color: #721c24; margin: 0;"></p>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="index.php" style="color: #667eea; text-decoration: none; font-size: 16px;">← 返回首页</a>
            </div>
        </div>
    </div>
    
    <script>
        // 初始化：设置百度Cookie默认值
        document.addEventListener('DOMContentLoaded', function() {
            const cookieInput = document.getElementById('baiduCookie');
            if (cookieInput && !cookieInput.value.trim()) {
                // 设置默认Cookie值
                cookieInput.value = 'BIDUPSID=D5A60FF060ADD83BD0AE0AFE948AA4B4; MAWEBCUID=web_WsOnLzzoZAmUCNMehbOqrTOgwzhmCtzyLcWDCWprFOsDjatMpE; STOKEN=1d31b227b5d5930e55882bc6d0c1a23e0bb48d04e8663828965d1ca8223e715c; BAIDUID=C75AD3B3E5969E15317C7A3BA5DF1844:FG=1; Hm_lvt_0db18a3ce977f2c77edf8e7a00bf159d=1757312055,1757488448; H_WISE_SIDS_BFESS=62325_63140_63327_63948_64005_64450_64559_64646_64715_64696_64826_64812_64816_64846_64835_64865_64879_64894_64912_64929_64953_64943_64967_64986_65047_65077_65085_65125_65141_65140_65137_65192; BDSFRCVID=lW4OJexroGWkcvQskROmEHtYogKKvV3TDYLEOwXPsp3LGJLVkUGtEG0Pt8lgCZu-2ZlgogKKX2OTHN5I_gt2O8TlEORN9JaZZ7WGtf8g0M5; H_BDCLCKID_SF=JRKtoDDhJI03fP36q46h2JQH-UnLqMnDX2OZ0l8KtDbNhnQTKRbO06knQt7JX4Rlym38QbrmWIQthIoH3fnV0b070MvIbfCOBtj4KKJx0-PWeIJoLt7OQ-6DhUJiBhkHBan72D5YXb3MhlA93-vpXpDNet6KXqIfQ2vJ0DnjtPPhbC-GjTL5j6jbepJf-K6BbTn0sJOJfKolEq7_bf--DlDdbPryQRJWLJb9Kln2yR7JEtbTyPnxy5K_3lLj0ToWtnuj0UJkJRRvSbOHQT3mh45bbN3i-xrX0jRxWb3cWMPK8UbSX6ozBP-3D4POLP74BarwVlcqyfFbSp3mb67JD-50eGDfqTKHJRAs3bRVKbk_HJRY2Jo_q4tehHRBWU59WDTOQJ7TQbvjfxjRMp5cjxLn5bryKMnibHFe-pbwBpKKJqnG-jJd-n-mXbjWLxbJ3mkjbP-yan3To4KzDl6SbP4syPRvJfRnWg_tax7jbIocbMb1QfnCX6Fp-J6zthbxbIFO0KJzJCF-MIPCD6_5ePtjMfbWetoQ265tWjrJabC3MUnmXU6q2bDeQnJW5b5I-K3N2b6NWPbZDn3oynj4Dp0vWtv4aRQ8LDQ-ahRKLqT4jU5j5MonDh83eMvM3hTtHRrzWn3O5hvvoKoO3M7FjfFmbp8HW5vnagT9bquXWJjAoC35QnOte-bQXH_Etj-OJJutoKvt-5rDHJTg5DTjhPrMhbtJWMT-MTryKM3oK-QGqnv-XPcpyb04hUJiBb5ptanRhlRNB-3iV-OxDUvnyxAZMnox-UQxtNRJVnRj0MchKDoLh-JobUPUWa59LUvwJm5d0---BKnxDh5O0xIBy4_lWGJU0b3hfIkj2CKLK-oj-DDCejLW3j; H_PS_PSSID=63140_63327_63948_64005_64450_64559_64646_64696_64826_64812_64816_64846_64835_64865_64879_64894_64912_64929_64953_64943_64967_64986_65047_65077_65085_65125_65141_65140_65137_65192_65203_65234; H_WISE_SIDS=63140_63327_63948_64005_64450_64559_64646_64696_64826_64812_64816_64846_64835_64865_64879_64894_64912_64929_64953_64943_64967_64986_65047_65077_65085_65125_65141_65140_65137_65192_65203_65234; BAIDUID_BFESS=C75AD3B3E5969E15317C7A3BA5DF1844:FG=1; BDSFRCVID_BFESS=lW4OJexroGWkcvQskROmEHtYogKKvV3TDYLEOwXPsp3LGJLVkUGtEG0Pt8lgCZu-2ZlgogKKX2OTHN5I_gt2O8TlEORN9JaZZ7WGtf8g0M5; H_BDCLCKID_SF_BFESS=JRKtoDDhJI03fP36q46h2JQH-UnLqMnDX2OZ0l8KtDbNhnQTKRbO06knQt7JX4Rlym38QbrmWIQthIoH3fnV0b070MvIbfCOBtj4KKJx0-PWeIJoLt7OQ-6DhUJiBhkHBan72D5YXb3MhlA93-vpXpDNet6KXqIfQ2vJ0DnjtPPhbC-GjTL5j6jbepJf-K6BbTn0sJOJfKolEq7_bf--DlDdbPryQRJWLJb9Kln2yR7JEtbTyPnxy5K_3lLj0ToWtnuj0UJkJRRvSbOHQT3mh45bbN3i-xrX0jRxWb3cWMPK8UbSX6ozBP-3D4POLP74BarwVlcqyfFbSp3mb67JD-50eGDfqTKHJRAs3bRVKbk_HJRY2Jo_q4tehHRBWU59WDTOQJ7TQbvjfxjRMp5cjxLn5bryKMnibHFe-pbwBpKKJqnG-jJd-n-mXbjWLxbJ3mkjbP-yan3To4KzDl6SbP4syPRvJfRnWg_tax7jbIocbMb1QfnCX6Fp-J6zthbxbIFO0KJzJCF-MIPCD6_5ePtjMfbWetoQ265tWjrJabC3MUnmXU6q2bDeQnJW5b5I-K3N2b6NWPbZDn3oynj4Dp0vWtv4aRQ8LDQ-ahRKLqT4jU5j5MonDh83eMvM3hTtHRrzWn3O5hvvoKoO3M7FjfFmbp8HW5vnagT9bquXWJjAoC35QnOte-bQXH_Etj-OJJutoKvt-5rDHJTg5DTjhPrMhbtJWMT-MTryKM3oK-QGqnv-XPcpyb04hUJiBb5ptanRhlRNB-3iV-OxDUvnyxAZMnox-UQxtNRJVnRj0MchKDoLh-JobUPUWa59LUvwJm5d0---BKnxDh5O0xIBy4_lWGJU0b3hfIkj2CKLK-oj-DDCejLW3j; BA_HECTOR=8k2h218k8haga1ah852k0h80a5ag8l1kc4pdk24; ZFY=NW1nw1w8ZuZ2L:AMYz4QQbJZkKOgN9teGagMJOgNNcFc:C; BDORZ=FFFB88E999055A3F8A630C64834BD6D0; delPer=0; PSINO=6; BDRCVFR[3c3vu6WQ_iD]=mk3SLVN4HKm; Hm_lvt_b38046c48c4227252c63b3db9ea3fac2=1757311781,1757314613,1757571586; HMACCOUNT=D8886C13B2ECEBB9; __cas__st__558=NLI; __cas__id__558=0; __cas__rn__558=0; ppfuid=FOCoIC3q5fKa8fgJnwzbE67EJ49BGJeplOzf+4l4EOvDuu2RXBRv6R3A1AZMa49I27C0gDDLrJyxcIIeAeEhD8JYsoLTpBiaCXhLqvzbzmvy3SeAW17tKgNq/Xx+RgOdb8TWCFe62MVrDTY6lMf2GrfqL8c87KLF2qFER3obJGmXRS7RSHsClUBpvjWIIpNsGEimjy3MrXEpSuItnI4KD6at3O8SSNv6aVVdLQf4XRYCO7JYopKlS2qSbToeLz6S7vitljRUmErdaDZjkqsDoRJsVwXkGdF24AsEQ3K5XBbh9EHAWDOg2T1ejpq0s2eFy9ar/j566XqWDobGoNNfmamwr5nBFZFvilyKi2vR5HFtJfhN1eLb/i/C9hcVPjDWFCMUN0p4SXVVUMsKNJv2T2Q0Rs14gDuqHJ3rxHJuOGO4LkPV+7TROLMG0V6r0A++zkWOdjFiy1eD/0R8HcRWYhY5n+BXjfl1KhHW/K418gqp+HBavJhpxl858h16cMtKQmxzisHOxsE/KMoDNYYE7ucLE22Bi0Ojbor7y6SXfVj7+B4iuZO+f7FUDWABtt/WWQqHKVfXMaw5WUmKnfSR5wwQa+N01amx6X+p+x97kkGmoNOSwxWgGvuezNFuiJQdt51yrWaL9Re9fZveXFsIu/gzGjL50VLcWv2NICayyI8BE9m62pdBPySuv4pVqQ9Sl1uTC//wIcO7QL9nm+0N6JgtCkSAWOZCh7Lr0XP6QztjlyD3bkwYJ4FTiNanaDaD27kBzbWracbQl3oGM+3Bg6/RN682cL6ydljml/920Ulh0GvEV9dnyTGKy8XFjCQiSGk66HDxtjKMU4HPNa0dtv9tmNi5OLf3trbXvvpFbLWEB8hf4s5ieWYkugh95AkbJXzxzBwzWy+n7NUONSlz61f6EoK62gY5LGh4PT3C0+8ovD8fBU1C9+vRozHhhBUtHHxUnYx9OEBH0ljzkFSY+Oo6VuGtuVcWQFAbufgkqJnJqWT1fbYVd7Yyx2Kk4cXFJQdKps+jY88nMSivXabqVOFHtiCaV8u3uSe0kPld4zsYRDDc4ujl2xJR5AN3q8OeRvvb9Mxhxs9bjxa5KdKAwMvzbQbq/mwgjd9siXUizBEYRDDc4ujl2xJR5AN3q8Oe1WWULX5oIJzwrbxFaliZTRLbhH0MNlXHePf60sunDcFG4X+UjvIZDl0Se0IQy2dV/vM3+ee9J4qSxVIU2HxIuBTllnZC81hhWPgxy+x2ZmXayxvT1iTUpRrGE132K7Dr; BDUSS=dQOEpoRkJWbnZ1N2VsNzMtLXU5VHNZTGlxNXdvNmN1MHVhVUwtc0JtN2s5LWxvRUFBQUFBJCQAAAAAAAAAAAEAAABsQRDovsl5aW7FsNDEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAORqwmjkasJocj; BDUSS_BFESS=dQOEpoRkJWbnZ1N2VsNzMtLXU5VHNZTGlxNXdvNmN1MHVhVUwtc0JtN2s5LWxvRUFBQUFBJCQAAAAAAAAAAAEAAABsQRDovsl5aW7FsNDEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAORqwmjkasJocj; ab_sr=1.0.1_ZGQ2ODMwNTk4MzlmZDc3OTNjMjNiMDM5ZWYwMmJjMGVjZmY1ODQ0ZWNmOGU5ODkwOGQ4NDU1NDljZDNjMTYxOGFmMmVhNDczNDU4Y2NjN2Q5YTMxZDYyNTcwNzZjMWZhOTQ3YmY3N2JhNzllZjY3ZDUwZTk4MjUwZGVmYTk5OTAyYzhkYzllZDFjZTZlMTRmYTI0NGJjNzFmNDZiMTJiYjYzNGFhODc3NzBlY2U0ZGI1YmFhM2RkODIwNmU2NzUyZGQwNWM4YWNiYmVjMTc5ZGQ1YTM5YzkzZTRjYWI3MGQ=; LOGIN_OPT_=0; __cas__rn__=0;';
            }
        });
        
        // 从输入文本中提取和清理链接
        function extractAndCleanUrl(input) {
            // 移除多余的空格和换行符
            input = input.replace(/\s+/g, ' ').trim();
            
            // 如果输入本身就是URL格式，先清理
            if (input.startsWith('http://') || input.startsWith('https://')) {
                // 清理URL末尾可能的中文标点符号和非URL字符
                let url = input.replace(/[^\w\-\.\/\?\=\&\%\#\:\+]+$/g, '');
                return url;
            }
            
            // 使用正则表达式直接匹配URL
            // 匹配 http:// 或 https:// 开头的URL，直到遇到空格、中文字符或非URL字符
            // 使用非贪婪匹配，匹配到第一个停止字符为止
            const urlPatterns = [
                // 优先匹配小红书链接
                /https?:\/\/xhslink\.com\/[a-zA-Z0-9\/\-_]+(?=\s|$|[^\w\-\.\/\?\=\&\%\#\:\+])/,
                /https?:\/\/www\.xiaohongshu\.com\/[a-zA-Z0-9\/\-_]+(?=\s|$|[^\w\-\.\/\?\=\&\%\#\:\+])/,
                // 通用URL匹配
                /https?:\/\/[^\s\u4e00-\u9fff\！\？\。\，\；\：\、\…\（\）\【\】\《\》\「\」\『\』\）\]\}\"\'\,]+/
            ];
            
            for (let pattern of urlPatterns) {
                const match = input.match(pattern);
                if (match) {
                    let url = match[0];
                    // 清理URL末尾可能的非URL字符
                    url = url.replace(/[^\w\-\.\/\?\=\&\%\#\:\+]+$/g, '');
                    
                    // 验证是否为小红书链接
                    if (url.includes('xhslink.com') || url.includes('xiaohongshu.com')) {
                        return url;
                    }
                }
            }
            
            return null; // 如果无法提取，返回 null
        }
        
        // 表单提交
        document.getElementById('xhsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const input = document.getElementById('xhsUrl').value.trim();
            
            if (!input) {
                alert('请输入小红书链接');
                document.getElementById('xhsUrl').focus();
                return;
            }
            
            // 从输入文本中提取链接
            const url = extractAndCleanUrl(input);
            
            if (!url) {
                alert('未找到有效的小红书链接。请确保输入内容包含完整的小红书链接（支持 xiaohongshu.com 或 xhslink.com）。');
                document.getElementById('xhsUrl').focus();
                return;
            }
            
            // 验证是否为小红书链接
            if (url.indexOf('xiaohongshu.com') === -1 && url.indexOf('xhslink.com') === -1) {
                alert('请输入有效的小红书链接（支持 xiaohongshu.com 或 xhslink.com）');
                document.getElementById('xhsUrl').focus();
                return;
            }
            
            // 如果提取的链接与输入不同，更新输入框显示
            if (url !== input) {
                document.getElementById('xhsUrl').value = url;
            }
            
            saveXHSContent(url);
        });
        
        // 保存小红书内容
        function saveXHSContent(url) {
            const btn = document.getElementById('saveBtn');
            const btnText = btn.querySelector('.btn-text');
            const btnLoading = btn.querySelector('.btn-loading');
            const loading = document.getElementById('loading');
            const resultSection = document.getElementById('resultSection');
            const successDiv = document.getElementById('successDiv');
            const errorDiv = document.getElementById('errorDiv');
            
            // 显示加载状态
            btn.disabled = true;
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline';
            loading.className = 'loading show';
            resultSection.style.display = 'none';
            successDiv.style.display = 'none';
            errorDiv.style.display = 'none';
            
            // 发送请求
            const formData = new FormData();
            formData.append('action', 'save_xiaohongshu');
            formData.append('url', url);
            
            // 获取Cookie（如果设置了）
            const cookie = document.getElementById('baiduCookie').value.trim();
            if (cookie) {
                formData.append('cookie', cookie);
            }
            
            fetch('save_xiaohongshu.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loading.className = 'loading';
                
                if (data.success) {
                    // 显示成功结果
                    resultSection.style.display = 'block';
                    resultSection.className = 'result-section success';
                    successDiv.style.display = 'block';
                    
                    // 显示文章标题
                    if (data.title) {
                        document.getElementById('articleTitleDisplay').textContent = data.title;
                    }
                    
                    // 显示作者
                    const authorDisplay = document.getElementById('articleAuthorDisplay');
                    if (data.author && data.author.trim()) {
                        authorDisplay.textContent = data.author;
                        authorDisplay.style.display = 'block';
                    } else {
                        authorDisplay.style.display = 'none';
                    }
                    
                    // 显示描述
                    const descDisplay = document.getElementById('articleDescDisplay');
                    if (data.desc && data.desc.trim()) {
                        descDisplay.textContent = data.desc;
                        descDisplay.style.display = 'block';
                    } else {
                        descDisplay.style.display = 'none';
                    }
                    
                    // 显示图片数量
                    const imagesCount = data.images_count || 0;
                    let imagesText = imagesCount + ' 张图片';
                    if (data.warnings && data.warnings.length > 0) {
                        imagesText += '（部分图片下载失败）';
                    }
                    document.getElementById('imagesCountDisplay').textContent = imagesText;
                    
                    // 显示图片到左侧图集
                    const imagesGrid = document.getElementById('imagesGrid');
                    imagesGrid.innerHTML = '';
                    if (data.images && data.images.length > 0) {
                        data.images.forEach((imageUrl, index) => {
                            const imageItem = document.createElement('div');
                            imageItem.className = 'image-item';
                            imageItem.onclick = function() {
                                window.open(imageUrl, '_blank');
                            };
                            
                            const img = document.createElement('img');
                            img.src = imageUrl;
                            img.alt = '小红书图片 ' + (index + 1);
                            img.loading = 'lazy';
                            
                            imageItem.appendChild(img);
                            imagesGrid.appendChild(imageItem);
                        });
                    } else {
                        imagesGrid.innerHTML = '<p style="text-align: center; color: #6c757d; padding: 20px;">暂无图片</p>';
                    }
                    
                    // 显示HTML文件信息
                    if (data.html_file) {
                        const htmlFileInfo = document.getElementById('htmlFileInfo');
                        htmlFileInfo.style.display = 'block';
                        document.getElementById('htmlFileName').textContent = data.html_file.filename;
                        document.getElementById('htmlFileUrl').href = data.html_file.url;
                        document.getElementById('htmlFileUrl').textContent = data.html_file.url;
                        document.getElementById('viewHtmlBtn').href = data.html_file.url;
                        
                        // 复制链接功能 - 使用事件委托，避免重复绑定
                        const copyBtn = document.getElementById('copyHtmlBtn');
                        // 移除旧的事件监听器（通过克隆节点）
                        const newCopyBtn = copyBtn.cloneNode(true);
                        copyBtn.parentNode.replaceChild(newCopyBtn, copyBtn);
                        // 添加新的事件监听器
                        newCopyBtn.addEventListener('click', function() {
                            copyToClipboard(data.html_file.url);
                        });
                    }
                    
                    // 清空表单
                    document.getElementById('xhsUrl').value = '';
                    
                    // 滚动到结果区域
                    setTimeout(() => {
                        resultSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                } else {
                    // 显示错误
                    resultSection.style.display = 'block';
                    resultSection.className = 'result-section error';
                    errorDiv.style.display = 'block';
                    document.getElementById('errorMessage').textContent = data.error;
                }
            })
            .catch(error => {
                loading.className = 'loading';
                resultSection.style.display = 'block';
                resultSection.className = 'result-section error';
                errorDiv.style.display = 'block';
                document.getElementById('errorMessage').textContent = '请求失败：' + error.message;
            })
            .finally(() => {
                // 恢复按钮状态
                btn.disabled = false;
                btnText.style.display = 'inline';
                btnLoading.style.display = 'none';
            });
        }
        
        // 复制到剪贴板
        function copyToClipboard(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    showSuccessMessage('链接已复制到剪贴板');
                }).catch(() => {
                    fallbackCopyTextToClipboard(text);
                });
            } else {
                fallbackCopyTextToClipboard(text);
            }
        }
        
        // 备用复制方法
        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showSuccessMessage('链接已复制到剪贴板');
                } else {
                    alert('复制失败，请手动复制：' + text);
                }
            } catch (err) {
                alert('复制失败，请手动复制：' + text);
            }
            
            document.body.removeChild(textArea);
        }
        
        // 显示成功消息
        function showSuccessMessage(message) {
            // 移除现有的成功消息
            const existingMessage = document.querySelector('.success-message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            // 创建新的成功消息
            const successDiv = document.createElement('div');
            successDiv.className = 'success-message';
            successDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #4caf50; color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10000; font-size: 14px;';
            successDiv.textContent = message;
            
            document.body.appendChild(successDiv);
            
            // 3秒后自动移除
            setTimeout(() => {
                if (successDiv.parentNode) {
                    successDiv.remove();
                }
            }, 3000);
        }
    </script>
</body>
</html>

