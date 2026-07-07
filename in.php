<?php
// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 清理和标准化URL
function cleanAndStandardizeUrl($input) {
    // 移除多余的空格和换行符
    $input = preg_replace('/\s+/', ' ', trim($input));
    
    // 匹配各种平台链接的正则表达式
    $urlPatterns = [
        // 抖音链接
        '/https?:\/\/v\.douyin\.com\/[a-zA-Z0-9]+\/?/',
        '/https?:\/\/www\.douyin\.com\/video\/[0-9]+\/?/',
        '/https?:\/\/douyin\.com\/video\/[0-9]+\/?/',
        '/https?:\/\/www\.iesdouyin\.com\/share\/video\/[0-9]+\/?/',
        '/https?:\/\/iesdouyin\.com\/share\/video\/[0-9]+\/?/',
        // 小红书链接 - 支持各种格式，包括 /o/ 路径
        '/https?:\/\/www\.xiaohongshu\.com\/explore\/[a-zA-Z0-9]+/',
        '/https?:\/\/xhslink\.com\/[a-zA-Z0-9\/]+[a-zA-Z0-9]+(?:\?[^\s\)\]\}\"\'\,]*)?/',  // 支持 /o/ 路径，如 /o/AiCioE08yX
        '/https?:\/\/xhslink\.com\/[a-zA-Z0-9]+/',
        // 快手链接
        '/https?:\/\/www\.kuaishou\.com\/video\/[a-zA-Z0-9]+/',
        '/https?:\/\/v\.kuaishou\.com\/[a-zA-Z0-9]+/',
        // B站链接
        '/https?:\/\/www\.bilibili\.com\/video\/[a-zA-Z0-9]+/',
        '/https?:\/\/b23\.tv\/[a-zA-Z0-9]+/'
    ];
    
    // 尝试匹配各种链接格式
    foreach ($urlPatterns as $pattern) {
        if (preg_match($pattern, $input, $matches)) {
            return $matches[0]; // 返回第一个匹配的链接
        }
    }
    
    // 如果没有找到标准格式的链接，尝试查找任何包含http的文本
    // 改进正则表达式，排除常见的标点符号和换行符
    if (preg_match('/https?:\/\/[^\s\)\]\}\"\'\,]+/', $input, $matches)) {
        $url = $matches[0];
        // 清理URL，移除可能的额外字符，但保留必要的URL字符
        // 特别处理小红书链接，保留 /o/ 路径
        if (strpos($url, 'xhslink.com') !== false) {
            // 对于小红书链接，保留路径部分
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
    
    // 如果输入本身就是URL格式，直接返回
    if (strpos($input, 'http://') === 0 || strpos($input, 'https://') === 0) {
        return $input;
    }
    
    return $input; // 如果无法清理，返回原始输入
}

// 处理视频链接去水印
function processVideoUrl($url) {
    $result = [
        'success' => false,
        'video_url' => '',
        'title' => '',
        'cover' => '',
        'duration' => '',
        'platform' => '',
        'error' => ''
    ];
    
    // 检测平台
    if (strpos($url, 'douyin.com') !== false || strpos($url, 'iesdouyin.com') !== false) {
        $result['platform'] = '抖音';
        $result = processDouyin($url);
    } elseif (strpos($url, 'xiaohongshu.com') !== false || strpos($url, 'xhslink.com') !== false) {
        $result['platform'] = '小红书';
        $result = processXiaohongshu($url);
    } elseif (strpos($url, 'kuaishou.com') !== false || strpos($url, 'kwai.com') !== false) {
        $result['platform'] = '快手';
        $result = processKuaishou($url);
    } elseif (strpos($url, 'bilibili.com') !== false) {
        $result['platform'] = 'B站';
        $result = processBilibili($url);
    } else {
        $result['error'] = '暂不支持该平台的视频链接';
    }
    
    return $result;
}

// 处理抖音视频
function processDouyin($url) {
    $result = [
        'success' => false,
        'video_url' => '',
        'title' => '',
        'cover' => '',
        'duration' => '',
        'platform' => '抖音',
        'error' => '',
        'author' => '',
        'aweme_id' => ''
    ];
    
    try {
        // 使用第三方API解析抖音视频
        $apiUrl = 'https://api.doukan1.top/32.php?url=' . urlencode($url);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            error_log("API Response: " . $response);
            $data = json_decode($response, true);
            error_log("Decoded data: " . print_r($data, true));
            
            // 检查多种可能的成功响应格式
            $isSuccess = false;
            
            // 格式1: 包含"解析成功"消息
            if (isset($data['msg']) && strpos($data['msg'], '解析成功') !== false) {
                $isSuccess = true;
            }
            // 格式2: 直接包含视频URL
            elseif (isset($data['play_video']) || isset($data['video']) || isset($data['video_url'])) {
                $isSuccess = true;
            }
            // 格式3: 包含code=200或success=true
            elseif ((isset($data['code']) && $data['code'] == 200) || (isset($data['success']) && $data['success'] === true)) {
                $isSuccess = true;
            }
            
            if ($isSuccess) {
                // 解析成功
                
                // 处理嵌套的data字段（你的API返回格式）
                $apiData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
                
                error_log("Using data from: " . print_r($apiData, true));
                
                $result['success'] = true;
                $result['video_url'] = $apiData['play_video'] ?? $apiData['video'] ?? $apiData['video_url'] ?? '';
                $result['title'] = $apiData['title'] ?? $apiData['desc'] ?? '';
                $result['cover'] = $apiData['cover'] ?? $apiData['cover_url'] ?? '';
                $result['author'] = $apiData['name'] ?? $apiData['author'] ?? $apiData['nickname'] ?? '';
                $result['aweme_id'] = $apiData['aweme_id'] ?? $apiData['id'] ?? '';
                
                // 如果有视频ID，可以获取更多信息
                if ($result['aweme_id']) {
                    $result['title'] = $result['title'] ?: "抖音视频 {$result['aweme_id']}";
                }
                
                error_log("Processed result: " . print_r($result, true));
            } else {
                // API解析失败，尝试备用方法
                error_log("API parsing failed, trying fallback method");
                $result = processDouyinFallback($url);
            }
        } else {
            $result['error'] = 'API请求失败，HTTP状态码: ' . $httpCode . '，请稍后重试';
            error_log("API request failed. HTTP Code: " . $httpCode . ", Response: " . $response);
        }
    } catch (Exception $e) {
        $result['error'] = '处理失败: ' . $e->getMessage();
        error_log("Exception in processDouyin: " . $e->getMessage());
    }
    
    return $result;
}

// 抖音视频备用解析方法
function processDouyinFallback($url) {
    $result = [
        'success' => false,
        'video_url' => '',
        'title' => '',
        'cover' => '',
        'duration' => '',
        'platform' => '抖音',
        'error' => '',
        'author' => '',
        'aweme_id' => ''
    ];
    
    try {
        // 模拟浏览器请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            // 提取视频信息
            if (preg_match('/"playAddr":"([^"]+)"/', $response, $matches)) {
                $videoUrl = str_replace('\\u002F', '/', $matches[1]);
                $result['video_url'] = $videoUrl;
            }
            
            if (preg_match('/"title":"([^"]+)"/', $response, $matches)) {
                $result['title'] = $matches[1];
            }
            
            if (preg_match('/"cover":"([^"]+)"/', $response, $matches)) {
                $result['cover'] = str_replace('\\u002F', '/', $matches[1]);
            }
            
            if (preg_match('/"duration":(\d+)/', $response, $matches)) {
                $result['duration'] = gmdate('H:i:s', $matches[1]);
            }
            
            if (preg_match('/"aweme_id":"([^"]+)"/', $response, $matches)) {
                $result['aweme_id'] = $matches[1];
            }
            
            if ($result['video_url']) {
                $result['success'] = true;
                error_log("Fallback method succeeded: " . print_r($result, true));
            } else {
                $result['error'] = '无法提取视频链接';
                error_log("Fallback method failed - no video URL found");
            }
        } else {
            $result['error'] = '无法获取视频信息';
        }
    } catch (Exception $e) {
        $result['error'] = '备用解析失败: ' . $e->getMessage();
    }
    
    return $result;
}

// 处理小红书视频
function processXiaohongshu($url) {
    $result = [
        'success' => false,
        'video_url' => '',
        'title' => '',
        'cover' => '',
        'duration' => '',
        'platform' => '小红书',
        'error' => ''
    ];
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            // 小红书视频信息提取
            if (preg_match('/"videoUrl":"([^"]+)"/', $response, $matches)) {
                $result['video_url'] = $matches[1];
            }
            
            if (preg_match('/"title":"([^"]+)"/', $response, $matches)) {
                $result['title'] = $matches[1];
            }
            
            if ($result['video_url']) {
                $result['success'] = true;
            }
        }
    } catch (Exception $e) {
        $result['error'] = '处理失败: ' . $e->getMessage();
    }
    
    return $result;
}

// 处理快手视频
function processKuaishou($url) {
    $result = [
        'success' => false,
        'video_url' => '',
        'title' => '',
        'cover' => '',
        'duration' => '',
        'platform' => '快手',
        'error' => ''
    ];
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            // 快手视频信息提取
            if (preg_match('/"playUrl":"([^"]+)"/', $response, $matches)) {
                $result['video_url'] = $matches[1];
            }
            
            if (preg_match('/"caption":"([^"]+)"/', $response, $matches)) {
                $result['title'] = $matches[1];
            }
            
            if ($result['video_url']) {
                $result['success'] = true;
            }
        }
    } catch (Exception $e) {
        $result['error'] = '处理失败: ' . $e->getMessage();
    }
    
    return $result;
}

// 处理B站视频
function processBilibili($url) {
    $result = [
        'success' => false,
        'video_url' => '',
        'title' => '',
        'cover' => '',
        'duration' => '',
        'platform' => 'B站',
        'error' => ''
    ];
    
    try {
        // B站视频处理逻辑
        $result['error'] = 'B站视频暂不支持直接去水印';
    } catch (Exception $e) {
        $result['error'] = '处理失败: ' . $e->getMessage();
    }
    
    return $result;
}

// 生成HTML文件
function generateHtmlFile($videoData, $originalUrl) {
    try {
        // 确保post目录存在
        $postDir = 'post';
        if (!is_dir($postDir)) {
            mkdir($postDir, 0755, true);
        }
        
        // 生成唯一文件名
        $filename = generateUniqueFilename($videoData);
        $filepath = $postDir . '/' . $filename;
        
        // 读取模板
        $templatePath = $postDir . '/template.html';
        if (!file_exists($templatePath)) {
            // 如果模板文件不存在，创建一个默认模板
            $template = '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>{{TITLE}}</title>
    <meta name="description" content="{{DESCRIPTION}}">
    <meta name="keywords" content="{{KEYWORDS}}">
    <meta name="author" content="{{AUTHOR}}">
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 20px; }
        .video-container { max-width: 800px; margin: 0 auto; }
        video { width: 100%; max-width: 100%; }
        .download-btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
    </style>
</head>
<body>
    <h1>{{TITLE}}</h1>
    <p>平台：{{PLATFORM}}</p>
    <p>作者：{{AUTHOR}}</p>
    <div class="video-container">
        <video controls>
            <source src="{{VIDEO_URL}}" type="video/mp4">
        </video>
    </div>
    <a href="{{VIDEO_URL}}" download="{{DOWNLOAD_FILENAME}}" class="download-btn">下载视频</a>
</body>
</html>';
        } else {
            $template = file_get_contents($templatePath);
            if (!$template) {
                throw new Exception('无法读取HTML模板');
            }
        }
        
        // 准备替换数据
        $replacements = [
            '{{TITLE}}' => htmlspecialchars($videoData['title'] ?: '无标题视频'),
            '{{DESCRIPTION}}' => htmlspecialchars($videoData['title'] ?: '视频去水印处理结果'),
            '{{KEYWORDS}}' => htmlspecialchars($videoData['platform'] . ',视频去水印,' . ($videoData['author'] ?: '')),
            '{{AUTHOR}}' => htmlspecialchars($videoData['author'] ?: '未知作者'),
            '{{PLATFORM}}' => htmlspecialchars($videoData['platform']),
            '{{DURATION}}' => htmlspecialchars($videoData['duration'] ?: '未知'),
            '{{VIDEO_URL}}' => htmlspecialchars($videoData['video_url']),
            '{{COVER_IMAGE}}' => htmlspecialchars($videoData['cover'] ?: ''),
            '{{AWEME_ID}}' => htmlspecialchars($videoData['aweme_id'] ?: ''),
            '{{ORIGINAL_URL}}' => htmlspecialchars($originalUrl),
            '{{PAGE_URL}}' => 'http://' . $_SERVER['HTTP_HOST'] . '/' . $filepath,
            '{{PROCESS_TIME}}' => date('Y-m-d H:i:s'),
            '{{UPLOAD_DATE}}' => date('c'),
            '{{GENERATE_TIME}}' => date('Y-m-d H:i:s'),
            '{{DOWNLOAD_FILENAME}}' => generateDownloadFilename($videoData),
            '{{FILE_SIZE}}' => '未知'
        ];
        
        // 替换模板内容
        $htmlContent = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // 写入文件
        if (file_put_contents($filepath, $htmlContent) === false) {
            throw new Exception('无法写入HTML文件');
        }
        
        // 设置文件权限
        chmod($filepath, 0644);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'url' => 'http://' . $_SERVER['HTTP_HOST'] . '/' . $filepath
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// 生成唯一文件名
function generateUniqueFilename($videoData) {
    $timestamp = date('Ymd_His');
    $platform = strtolower($videoData['platform']);
    
    // 基础文件名
    $baseName = $platform . '_' . $timestamp;
    
    // 如果有视频ID，添加到文件名中
    if (!empty($videoData['aweme_id'])) {
        $baseName .= '_' . substr($videoData['aweme_id'], -8); // 取ID后8位
    }
    
    // 如果有作者信息，添加到文件名中
    if (!empty($videoData['author'])) {
        $author = preg_replace('/[^a-zA-Z0-9\u4e00-\u9fa5]/', '', $videoData['author']);
        $author = mb_substr($author, 0, 10); // 限制长度
        if ($author) {
            $baseName .= '_' . $author;
        }
    }
    
    // 检查文件是否已存在，如果存在则添加序号
    $filename = $baseName . '.html';
    $counter = 1;
    
    while (file_exists('post/' . $filename)) {
        $filename = $baseName . '_' . $counter . '.html';
        $counter++;
        
        // 防止无限循环
        if ($counter > 999) {
            $filename = $baseName . '_' . uniqid() . '.html';
            break;
        }
    }
    
    return $filename;
}

// 生成下载文件名
function generateDownloadFilename($videoData) {
    $filename = '';
    
    // 清理标题，移除特殊字符
    if (!empty($videoData['title'])) {
        $filename = preg_replace('/[<>:"\/\\\\|?*]/', '', $videoData['title']);
        $filename = mb_substr($filename, 0, 30); // 限制长度
    }
    
    // 添加作者信息
    if (!empty($videoData['author'])) {
        $author = preg_replace('/[<>:"\/\\\\|?*]/', '', $videoData['author']);
        $author = mb_substr($author, 0, 10);
        if ($author) {
            $filename = $author . '_' . $filename;
        }
    }
    
    // 添加平台标识
    $filename = $filename . '_' . $videoData['platform'];
    
    // 确保文件名不为空
    if (empty(trim($filename))) {
        $filename = 'video_' . date('Ymd_His');
    }
    
    // 添加扩展名
    $filename .= '.mp4';
    
    return $filename;
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_video') {
    header('Content-Type: application/json');
    
    if (isset($_POST['url']) && !empty($_POST['url'])) {
        $url = trim($_POST['url']);
        
        // 清理和标准化URL
        $url = cleanAndStandardizeUrl($url);
        
        // 检查IP访问限制
        $accessCheck = [
            'allowed' => true,
            'remaining' => 999,
            'reset_time' => time() + 3600,
            'message' => ''
        ];
        if (file_exists('ip_limit.php')) {
            require_once 'ip_limit.php';
            if (function_exists('checkIPAccess')) {
                $accessCheck = checkIPAccess($url, 2);
            }
        }
        
        if (!$accessCheck['allowed']) {
            echo json_encode([
                'success' => false,
                'error' => $accessCheck['message'],
                'access_info' => [
                    'remaining' => $accessCheck['remaining'],
                    'reset_time' => date('Y-m-d H:i:s', $accessCheck['reset_time'])
                ]
            ]);
            exit;
        }
        
        // 添加调试信息
        error_log("Processing video URL: " . $url);
        error_log("IP access check passed, remaining: " . $accessCheck['remaining']);
        
        $result = processVideoUrl($url);
        
        // 添加调试信息
        error_log("Process result: " . json_encode($result));
        
        // 如果解析成功，生成HTML文件
        if ($result['success']) {
            $htmlResult = generateHtmlFile($result, $url);
            if ($htmlResult['success']) {
                $result['html_file'] = [
                    'filename' => $htmlResult['filename'],
                    'url' => $htmlResult['url']
                ];
            } else {
                // HTML生成失败不影响主要功能
                $result['html_error'] = $htmlResult['error'];
            }
        }
        
        // 添加访问限制信息
        $result['access_info'] = [
            'remaining' => $accessCheck['remaining'],
            'reset_time' => date('Y-m-d H:i:s', $accessCheck['reset_time']),
            'message' => $accessCheck['message']
        ];
        
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'error' => '请输入视频链接']);
    }
    exit;
}

// 获取最新视频
function getLatestVideos($limit = 4) {
    $videos = [];
    $postDir = 'post';
    
    if (is_dir($postDir)) {
        $handle = opendir($postDir);
        while (($file = readdir($handle)) !== false) {
            if ($file != '.' && $file != '..' && $file != 'index.php' && $file != 'template.html' && pathinfo($file, PATHINFO_EXTENSION) === 'html') {
                $filepath = $postDir . '/' . $file;
                $content = file_get_contents($filepath);
                
                // 解析视频信息
                $videoInfo = parseVideoInfo($content, $file);
                if ($videoInfo) {
                    $videos[] = $videoInfo;
                }
            }
        }
        closedir($handle);
    }
    
    // 按修改时间排序（最新的在前）
    usort($videos, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
    
    return array_slice($videos, 0, $limit);
}

// 解析视频信息
function parseVideoInfo($content, $filename) {
    $info = [
        'filename' => $filename,
        'modified' => filemtime('post/' . $filename),
        'url' => 'http://' . $_SERVER['HTTP_HOST'] . '/post/' . $filename,
        'title' => '',
        'author' => '',
        'platform' => '',
        'duration' => '',
        'cover' => ''
    ];
    
    // 提取标题
    if (preg_match('/<title>([^<]+)<\/title>/i', $content, $matches)) {
        $info['title'] = trim($matches[1]);
    }
    
    // 提取作者
    if (preg_match('/<meta name="author" content="([^"]+)"/i', $content, $matches)) {
        $info['author'] = trim($matches[1]);
    }
    
    // 提取平台
    if (preg_match('/<meta name="keywords" content="([^"]+)"/i', $content, $matches)) {
        $keywords = $matches[1];
        if (strpos($keywords, '抖音') !== false) {
            $info['platform'] = '抖音';
        } elseif (strpos($keywords, '小红书') !== false) {
            $info['platform'] = '小红书';
        } elseif (strpos($keywords, '快手') !== false) {
            $info['platform'] = '快手';
        } elseif (strpos($keywords, 'B站') !== false) {
            $info['platform'] = 'B站';
        }
    }
    
    return $info;
}

// 获取最新文章
function getLatestArticles($limit = 4) {
    $articles = [];
    $articlesDir = 'articles';
    
    if (is_dir($articlesDir)) {
        $handle = opendir($articlesDir);
        while (($file = readdir($handle)) !== false) {
            if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) === 'html') {
                $filepath = $articlesDir . '/' . $file;
                $content = file_get_contents($filepath);
                
                // 解析文章信息
                $articleInfo = parseArticleInfo($content, $file);
                if ($articleInfo) {
                    $articles[] = $articleInfo;
                }
            }
        }
        closedir($handle);
    }
    
    // 按修改时间排序（最新的在前）
    usort($articles, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
    
    return array_slice($articles, 0, $limit);
}

// 解析文章信息
function parseArticleInfo($content, $filename) {
    $info = [
        'filename' => $filename,
        'modified' => filemtime('articles/' . $filename),
        'url' => 'http://' . $_SERVER['HTTP_HOST'] . '/articles/' . $filename,
        'title' => '',
        'description' => '',
        'author' => '小青去水印'
    ];
    
    // 提取标题
    if (preg_match('/<title>([^<]+)<\/title>/i', $content, $matches)) {
        $info['title'] = trim($matches[1]);
    }
    
    // 提取描述
    if (preg_match('/<meta name="description" content="([^"]+)"/i', $content, $matches)) {
        $info['description'] = trim($matches[1]);
    }
    
    return $info;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小青去水印工具 - 支持抖音、小红书、快手等平台</title>
    <meta name="description" content="专业的视频去水印工具，支持抖音、小红书、快手等主流平台，一键去除视频水印，高清下载，完全免费使用。">
    <meta name="keywords" content="视频去水印,抖音去水印,小红书去水印,快手去水印,免费去水印,视频下载">
    <meta name="author" content="视频去水印工具">
   <meta name="shenma-site-verification" content="b23c58a005925efc95e215c12ec6fefa_1768796760"/>
    <!-- Open Graph 标签 -->
    <meta property="og:title" content="小青">
    <meta property="og:description" content="支持抖音、小红书、快手等平台的视频去水印工具">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>">
    
    <!-- Twitter Card 标签 -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="小青">
    <meta name="twitter:description" content="支持抖音、小红书、快手等平台的视频去水印工具">
    
    <!-- 结构化数据 -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "去水印工具",
        "description": "专业的视频去水印工具，支持抖音、小红书、快手等主流平台",
        "url": "<?php echo 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>",
        "applicationCategory": "MultimediaApplication",
        "operatingSystem": "Web Browser"
    }
    </script>
    
    <link rel="canonical" href="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/x-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎬</text></svg>">
</head>
<body>
    <div class="container">
        <header class="header">
            <h1 class="logo">
                <span class="logo-icon">🎬</span>
                去水印工具
            </h1>
            <p class="subtitle">支持抖音、小红书、快手等主流平台，一键去除视频水印</p>
        </header>

        <main class="main">
            <div class="input-section">
                <div class="input-group">
                    <input type="url" id="videoUrl" placeholder="请粘贴视频链接或分享内容，支持抖音、小红书、快手等平台" class="url-input">
                    <button id="processBtn" class="process-btn">
                        <span class="btn-text">开始去水印</span>
                        <span class="btn-loading" style="display: none;">处理中...</span>
                    </button>
                </div>
                <div class="supported-platforms">
                    <span class="platform-tag">抖音</span>
                    <span class="platform-tag">小红书</span>
                    <span class="platform-tag">快手</span>
                    <span class="platform-tag">B站</span>
                </div>
                <div class="wechat-tip">
                    <div class="wechat-tip-content">
                        <span class="wechat-icon">💬</span>
                        <span class="wechat-text">推荐使用小程序：<strong>微信搜索"小青去水印"</strong>，体验更流畅！</span>
                    </div>
                </div>
               
            </div>

            <div id="resultSection" class="result-section" style="display: none;">
                <div class="result-header">
                    <h2>处理结果</h2>
                    <div class="result-info">
                        <span id="platformInfo" class="platform-info"></span>
                        <span id="processTime" class="process-time"></span>
                    </div>
                </div>
                
                <div class="video-container">
                    <div class="video-info">
                        <h3 id="videoTitle" class="video-title"></h3>
                        <div class="video-meta">
                            <span id="videoDuration" class="duration"></span>
                            <span id="videoSize" class="size"></span>
                        </div>
                    </div>
                    
                    <div class="video-player-container">
                        <video id="videoPlayer" controls preload="metadata" class="video-player">
                            您的浏览器不支持视频播放
                        </video>
                        <div id="videoCover" class="video-cover" style="display: none;">
                            <img src="" alt="视频封面" class="cover-image">
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button id="downloadBtn" class="download-btn">
                            <span class="btn-icon">⬇️</span>
                            下载视频
                        </button>
                        <button id="copyUrlBtn" class="copy-btn">
                            <span class="btn-icon">📋</span>
                            复制链接
                        </button>
                        <button id="shareBtn" class="share-btn">
                            <span class="btn-icon">🔗</span>
                            分享
                        </button>
                    </div>
                </div>
            </div>

            <div id="errorSection" class="error-section" style="display: none;">
                <div class="error-content">
                    <div class="error-icon">⚠️</div>
                    <h3>由于网页限制请到小程序下载</h3>
                    <p id="errorMessage">由于网页限制，请到小程序下载视频</p>
                    <button id="retryBtn" class="retry-btn" onclick="window.location.href='https://wxaurl.cn/1OmFmbue8if'">去小程序下载</button>
                </div>
            </div>
        </main>

        <footer class="footer">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>使用说明</h4>
                    <ul>
                        <li>复制视频分享链接</li>
                        <li>粘贴到上方输入框</li>
                        <li>点击"开始去水印"</li>
                        <li>等待处理完成</li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>支持平台</h4>
                    <ul>
                        <li>抖音短视频</li>
                        <li>小红书视频</li>
                        <li>快手短视频</li>
                        <li>更多平台持续更新</li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>注意事项</h4>
                    <ul>
                        <li>请确保链接有效</li>
                        <li>仅支持公开视频</li>
                        <li>请遵守版权规定</li>
                        <li>仅供学习交流使用</li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>最新视频</h4>
                    <ul>
                        <?php
                        // 获取最新4个视频
                        $latestVideos = getLatestVideos(4);
                        if (!empty($latestVideos)) {
                            foreach ($latestVideos as $video) {
                                echo '<li><a href="' . htmlspecialchars($video['url']) . '" style="color: white; text-decoration: none;" target="_blank">🎬 ' . htmlspecialchars(mb_substr($video['title'], 0, 20)) . '...</a></li>';
                            }
                        } else {
                            echo '<li style="color: white;">暂无视频</li>';
                        }
                        ?>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>最新文章</h4>
                    <div class="articles-grid">
                        <?php
                        // 获取最新12篇文章
                        $latestArticles = getLatestArticles(12);
                        if (!empty($latestArticles)) {
                            foreach ($latestArticles as $article) {
                                echo '<a href="articles/' . htmlspecialchars($article['filename']) . '" class="article-item" target="_blank">📝 ' . htmlspecialchars(mb_substr($article['title'], 0, 20)) . '...</a>';
                            }
                        } else {
                            echo '<div class="article-item" style="color: white;">暂无文章</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2024 视频去水印工具. 免费使用，请遵守相关法律法规.</p>
            </div>
        </footer>
    </div>

    <script src="script.js"></script>
</body>
</html>
