import sys
import os

def setup_runtime_paths():
    """打包为 exe 后，设置工作目录和 Playwright 浏览器路径"""
    if getattr(sys, 'frozen', False):
        base_dir = os.path.dirname(sys.executable)
        os.chdir(base_dir)
        browsers_dir = os.path.join(base_dir, 'browsers')
        os.environ.setdefault('PLAYWRIGHT_BROWSERS_PATH', browsers_dir)

setup_runtime_paths()

import requests
import xml.etree.ElementTree as ET
import json
from PyQt5.QtWidgets import (
    QApplication, QMainWindow, QWidget, QVBoxLayout, QHBoxLayout,
    QLineEdit, QPushButton, QTextEdit, QComboBox, QLabel,
    QProgressBar, QListWidget, QListWidgetItem, QSplitter,
    QGroupBox, QMessageBox, QFileDialog
)
from PyQt5.QtCore import Qt, QThread, pyqtSignal, QTimer
from playwright.sync_api import sync_playwright
import time

YUANBAO_BATCH_SIZE = 5
SERVICE_URLS = {
    "豆包": "https://www.doubao.com",
    "DeepSeek": "https://chat.deepseek.com",
    "元宝": "https://yuanbao.tencent.com/chat",
}

def build_question(service_type, urls):
    """根据服务类型和链接列表生成提问内容"""
    if isinstance(urls, str):
        urls = [urls]
    urls = [u for u in urls if u]

    if service_type == "元宝" and len(urls) > 1:
        links_text = "\n".join(urls)
        return f"{links_text}\n\n这几篇文章写的是什么？总结一下"
    return f"{urls[0]}\n\n这篇文章写得是什么？总结下"

def chunk_links(links, size=YUANBAO_BATCH_SIZE):
    """将链接按指定数量分批"""
    return [links[i:i + size] for i in range(0, len(links), size)]

def fill_and_send_message(page, service_type, question):
    """填写问题并点击发送，返回是否发送成功"""
    input_selectors_map = {
        "豆包": ['textarea.semi-input-textarea'],
        "DeepSeek": [
            'textarea[name="search"]',
            'textarea[placeholder*="DeepSeek"]',
        ],
        "元宝": [
            '#search-bar .ql-editor[contenteditable="true"]',
            'div.ql-editor[contenteditable="true"]',
        ],
    }
    input_selectors = input_selectors_map.get(service_type, ['textarea'])

    textarea = None
    input_selector = input_selectors[0]
    for sel in input_selectors:
        loc = page.locator(sel).first
        try:
            loc.wait_for(state="visible", timeout=10000)
            textarea = loc
            input_selector = sel
            break
        except Exception:
            continue
    if textarea is None:
        raise Exception(f"未找到{service_type}输入框")

    print(f"使用输入框: {input_selector}")
    textarea.click()
    textarea.fill(question)
    time.sleep(1)

    print(f"尝试发送消息 ({service_type})")
    sent = False

    if service_type == "豆包":
        send_selectors = [
            '#flow-end-msg-send',
            'div[class*="send"] button',
            'button:has(svg.size-18) >> nth=-1',
        ]
        for sel in send_selectors:
            try:
                btn = page.locator(sel)
                if btn.count() == 0:
                    continue
                btn.last.wait_for(state="visible", timeout=5000)
                btn.last.click(timeout=5000)
                print(f"豆包发送成功: {sel}")
                sent = True
                break
            except Exception as e:
                print(f"豆包发送失败 ({sel}): {e}")
    elif service_type == "DeepSeek":
        send_selectors = [
            'button:has(.ds-button__background)',
            '.ds-button__background',
        ]
        for sel in send_selectors:
            try:
                btn = page.locator(sel).last
                if btn.count() == 0 or not btn.is_visible():
                    continue
                btn.click(timeout=5000)
                print(f"DeepSeek发送成功: {sel}")
                sent = True
                break
            except Exception as e:
                print(f"DeepSeek发送失败 ({sel}): {e}")
        if not sent:
            try:
                page.eval_on_selector('.ds-button__background', '(el) => el.parentElement.click()')
                print("DeepSeek发送成功: 点击.ds-button__background父元素")
                sent = True
            except Exception as e:
                print(f"DeepSeek发送失败 (父元素): {e}")
    elif service_type == "元宝":
        send_selectors = [
            '#yuanbao-send-btn',
            'a.style__send-btn___RwTm5',
            'a[class*="send-btn"]',
        ]
        for sel in send_selectors:
            try:
                btn = page.locator(sel).first
                if btn.count() == 0 or not btn.is_visible():
                    continue
                btn.click(timeout=5000)
                print(f"元宝发送成功: {sel}")
                sent = True
                break
            except Exception as e:
                print(f"元宝发送失败 ({sel}): {e}")
    else:
        try:
            page.locator('button').last.click(timeout=5000)
            sent = True
        except Exception as e:
            print(f"默认发送失败: {e}")

    if not sent:
        print("按钮点击失败，尝试 Enter 键发送")
        try:
            textarea.press('Enter')
            sent = True
            print("Enter 键发送成功")
        except Exception as e:
            print(f"Enter 键发送失败: {e}")

    time.sleep(2)
    try:
        if service_type == "元宝":
            remaining = textarea.inner_text().strip()
        else:
            remaining = textarea.input_value().strip()
        if remaining:
            print(f"警告: 输入框仍有内容，可能未发送成功 (长度={len(remaining)})")
            return False
    except Exception:
        pass

    return sent

class SitemapParser:
    @staticmethod
    def extract_links(sitemap_url):
        links = []
        try:
            print(f"正在获取 sitemap: {sitemap_url}")
            response = requests.get(sitemap_url, timeout=30)
            response.encoding = 'utf-8'
            
            content = response.content.decode('utf-8', errors='ignore')
            print(f"sitemap 内容长度：{len(content)}")
            
            root = ET.fromstring(content)
            
            namespace = {'ns': 'http://www.sitemaps.org/schemas/sitemap/0.9'}
            url_elements = root.findall('ns:url', namespace)
            
            print(f"找到 url 元素数量：{len(url_elements)}")
            
            for url_element in url_elements:
                loc = url_element.find('ns:loc', namespace)
                if loc is not None and loc.text:
                    links.append(loc.text)
            
            if len(links) == 0:
                print("使用正则表达式备用解析...")
                import re
                locs = re.findall(r'<loc>([^<]+)</loc>', content)
                links = list(set(locs))
                print(f"正则表达式解析到 {len(links)} 个链接")
                
        except Exception as e:
            print(f"解析 sitemap 失败：{e}")
            import traceback
            traceback.print_exc()
        return links
    
    @staticmethod
    def extract_links_from_file(file_path):
        links = []
        try:
            print(f"正在读取本地文件: {file_path}")
            with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
            
            print(f"文件内容长度：{len(content)}")
            
            # 直接使用正则表达式快速解析，速度更快
            import re
            locs = re.findall(r'<loc>([^<]+)</loc>', content, re.IGNORECASE)
            links = list(set(locs))
            
            # 过滤掉空链接和无效链接
            links = [link.strip() for link in links if link.strip().startswith('http')]
            
            print(f"解析到 {len(links)} 个链接")
                
        except Exception as e:
            print(f"解析本地文件失败：{e}")
            import traceback
            traceback.print_exc()
        return links
    
    @staticmethod
    def save_links(links, save_path):
        try:
            with open(save_path, 'w', encoding='utf-8') as f:
                for link in links:
                    f.write(link + '\n')
            return True
        except Exception as e:
            print(f"保存链接失败：{e}")
            return False
    
    @staticmethod
    def load_links(load_path):
        links = []
        try:
            with open(load_path, 'r', encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if line:
                        links.append(line)
        except Exception as e:
            print(f"加载链接失败：{e}")
        return links

class BrowserController(QThread):
    finished = pyqtSignal(str)
    error = pyqtSignal(str)
    summary_ready = pyqtSignal(str)
    
    def __init__(self, service_type, article_urls):
        super().__init__()
        self.service_type = service_type
        self.article_urls = article_urls if isinstance(article_urls, list) else [article_urls]
        self.browser = None
        self.page = None
    
    def run(self):
        try:
            with sync_playwright() as p:
                self.browser = p.chromium.launch(headless=False, args=['--start-maximized'])
                self.page = self.browser.new_page()
                
                url = SERVICE_URLS.get(self.service_type, SERVICE_URLS["豆包"])
                self.page.goto(url, timeout=60000)
                
                time.sleep(5)
                
                self.enter_question()
                
                self.finished.emit("浏览器已打开，请在对话框中查看总结结果")
        except Exception as e:
            self.error.emit(f"浏览器控制失败：{str(e)}")
    
    def enter_question(self):
        try:
            question = build_question(self.service_type, self.article_urls)
            print(f"填写内容 ({len(self.article_urls)} 个链接): {question[:80]}...")
            fill_and_send_message(self.page, self.service_type, question)
            time.sleep(10)
        except Exception as e:
            print(f"输入问题失败：{e}")
            import traceback
            traceback.print_exc()

class MainWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("文章自动总结工具 - 浏览器版")
        self.setGeometry(100, 100, 1200, 800)
        
        self.article_links = []
        self.current_url = ""
        self.saved_links_file = "saved_links.txt"
        
        self.init_ui()
    
    def init_ui(self):
        central_widget = QWidget()
        self.setCentralWidget(central_widget)
        main_layout = QVBoxLayout(central_widget)
        
        top_layout = QHBoxLayout()
        
        self.sitemap_input = QLineEdit()
        self.sitemap_input.setPlaceholderText("请输入 sitemap.xml 链接，如：https://example.com/sitemap.xml")
        self.sitemap_input.setFixedWidth(500)
        
        self.parse_btn = QPushButton("解析链接")
        self.parse_btn.clicked.connect(self.parse_sitemap)
        
        self.file_btn = QPushButton("选择本地XML文件")
        self.file_btn.clicked.connect(self.select_local_file)
        
        self.save_btn = QPushButton("保存链接")
        self.save_btn.clicked.connect(self.save_links)
        self.save_btn.setEnabled(False)
        
        self.load_btn = QPushButton("加载已保存")
        self.load_btn.clicked.connect(self.load_links)
        
        top_layout.addWidget(QLabel("Sitemap 链接:"))
        top_layout.addWidget(self.sitemap_input)
        top_layout.addWidget(self.parse_btn)
        top_layout.addWidget(self.file_btn)
        top_layout.addWidget(self.save_btn)
        top_layout.addWidget(self.load_btn)
        
        main_layout.addLayout(top_layout)
        
        splitter = QSplitter(Qt.Horizontal)
        
        left_group = QGroupBox("提取的链接")
        left_layout = QVBoxLayout(left_group)
        
        filter_layout = QHBoxLayout()
        self.filter_input = QLineEdit()
        self.filter_input.setPlaceholderText("筛选链接...")
        self.filter_input.textChanged.connect(self.filter_links)
        filter_layout.addWidget(QLabel("筛选:"))
        filter_layout.addWidget(self.filter_input)
        left_layout.addLayout(filter_layout)
        
        self.link_list = QListWidget()
        self.link_list.itemDoubleClicked.connect(self.select_article)
        left_layout.addWidget(self.link_list)
        
        splitter.addWidget(left_group)
        
        right_group = QGroupBox("AI 服务选择")
        right_layout = QVBoxLayout(right_group)
        
        self.selected_url_label = QLabel("已选文章链接：")
        right_layout.addWidget(self.selected_url_label)
        
        self.url_display = QTextEdit()
        self.url_display.setReadOnly(True)
        self.url_display.setMaximumHeight(80)
        right_layout.addWidget(self.url_display)
        
        ai_layout = QHBoxLayout()
        
        self.service_combo = QComboBox()
        self.service_combo.addItems(["豆包", "DeepSeek", "元宝"])
        self.service_combo.setFixedWidth(150)
        
        self.summarize_btn = QPushButton("打开浏览器总结")
        self.summarize_btn.clicked.connect(self.summarize_article)
        self.summarize_btn.setEnabled(False)
        
        ai_layout.addWidget(QLabel("AI 服务:"))
        ai_layout.addWidget(self.service_combo)
        ai_layout.addStretch()
        ai_layout.addWidget(self.summarize_btn)
        
        right_layout.addLayout(ai_layout)
        
        # 自动循环选项
        auto_layout = QHBoxLayout()
        self.auto_btn = QPushButton("自动循环总结所有链接")
        self.auto_btn.clicked.connect(self.auto_summarize_all)
        self.auto_btn.setEnabled(False)
        
        self.delay_input = QLineEdit("5")
        self.delay_input.setPlaceholderText("间隔秒数")
        self.delay_input.setFixedWidth(80)
        
        auto_layout.addWidget(self.auto_btn)
        auto_layout.addWidget(QLabel("间隔"))
        auto_layout.addWidget(self.delay_input)
        auto_layout.addWidget(QLabel("秒"))
        
        right_layout.addLayout(auto_layout)
        
        tips_label = QLabel("提示：双击左侧链接选择文章，然后点击按钮打开浏览器进行总结（元宝每次发送5个链接）")
        tips_label.setStyleSheet("color: #666; font-size: 12px;")
        right_layout.addWidget(tips_label)
        
        splitter.addWidget(right_group)
        splitter.setStretchFactor(0, 1)
        splitter.setStretchFactor(1, 1)
        
        main_layout.addWidget(splitter)
        
        self.status_bar = self.statusBar()
        self.progress_bar = QProgressBar()
        self.progress_bar.setMaximumWidth(200)
        self.status_bar.addPermanentWidget(self.progress_bar)
    
    def parse_sitemap(self):
        sitemap_url = self.sitemap_input.text().strip()
        if not sitemap_url:
            QMessageBox.warning(self, "警告", "请输入 sitemap 链接")
            return
        
        self.status_bar.showMessage("正在解析 sitemap...")
        self.progress_bar.setRange(0, 0)
        
        def parse():
            links = SitemapParser.extract_links(sitemap_url)
            QTimer.singleShot(0, lambda: self.on_parse_complete(links))
        
        import threading
        threading.Thread(target=parse, daemon=True).start()
    
    def select_local_file(self):
        file_path, _ = QFileDialog.getOpenFileName(
            self, "选择本地XML文件", "", "XML文件 (*.xml);;所有文件 (*.*)"
        )
        
        if file_path:
            self.status_bar.showMessage(f"正在解析本地文件：{file_path}")
            self.progress_bar.setRange(0, 0)
            
            # 直接在主线程解析，不使用线程（文件解析非常快）
            try:
                print(f"开始解析文件: {file_path}")
                links = SitemapParser.extract_links_from_file(file_path)
                print(f"解析完成，找到 {len(links)} 个链接")
                self.update_link_list(links)
            except Exception as e:
                print(f"解析文件出错: {e}")
                import traceback
                traceback.print_exc()
                self.on_parse_error(str(e))
    
    def update_link_list(self, links):
        """更新链接列表UI"""
        self.article_links = links
        self.link_list.clear()
        for link in links:
            item = QListWidgetItem(link)
            self.link_list.addItem(item)
        
        self.save_btn.setEnabled(len(links) > 0)
        self.auto_btn.setEnabled(len(links) > 0)
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(100)
        self.status_bar.showMessage(f"解析完成，共找到 {len(links)} 个链接")
        
        if len(links) == 0:
            QMessageBox.warning(self, "警告", "未找到任何链接，请检查文件格式")
    
    def on_parse_error(self, error_msg):
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self.status_bar.showMessage(f"解析失败: {error_msg}")
        QMessageBox.critical(self, "错误", f"解析失败：{error_msg}")
    
    def on_parse_complete(self, links):
        self.article_links = links
        self.link_list.clear()
        
        for link in links:
            item = QListWidgetItem(link)
            self.link_list.addItem(item)
        
        self.save_btn.setEnabled(len(links) > 0)
        self.auto_btn.setEnabled(len(links) > 0)
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(100)
        self.status_bar.showMessage(f"解析完成，共找到 {len(links)} 个链接")
        
        if len(links) == 0:
            QMessageBox.warning(self, "警告", "未找到任何链接，请检查 sitemap 地址是否正确")
    
    def save_links(self):
        if len(self.article_links) == 0:
            QMessageBox.warning(self, "警告", "没有可保存的链接")
            return
        
        file_path, _ = QFileDialog.getSaveFileName(
            self, "保存链接", self.saved_links_file, "文本文件 (*.txt)"
        )
        
        if file_path:
            if SitemapParser.save_links(self.article_links, file_path):
                self.saved_links_file = file_path
                QMessageBox.information(self, "成功", f"已保存 {len(self.article_links)} 个链接")
                self.status_bar.showMessage(f"链接已保存到：{file_path}")
            else:
                QMessageBox.critical(self, "错误", "保存失败")
    
    def load_links(self):
        file_path, _ = QFileDialog.getOpenFileName(
            self, "加载链接", "", "文本文件 (*.txt)"
        )
        
        if file_path:
            links = SitemapParser.load_links(file_path)
            if links:
                self.article_links = links
                self.link_list.clear()
                for link in links:
                    item = QListWidgetItem(link)
                    self.link_list.addItem(item)
                self.status_bar.showMessage(f"已加载 {len(links)} 个链接")
            else:
                QMessageBox.warning(self, "警告", "文件中没有找到链接")
    
    def filter_links(self, text):
        for i in range(self.link_list.count()):
            item = self.link_list.item(i)
            if text.lower() in item.text().lower():
                item.setHidden(False)
            else:
                item.setHidden(True)
    
    def select_article(self, item):
        self.current_url = item.text()
        self.url_display.setPlainText(self.current_url)
        self.summarize_btn.setEnabled(True)
        self.status_bar.showMessage(f"已选择文章：{self.current_url}")
    
    def get_yuanbao_batch(self, start_url):
        """从选中链接开始，取最多5个链接作为元宝批次"""
        if start_url in self.article_links:
            idx = self.article_links.index(start_url)
            return self.article_links[idx:idx + YUANBAO_BATCH_SIZE]
        return [start_url]
    
    def summarize_article(self):
        if not self.current_url:
            QMessageBox.warning(self, "警告", "请先选择文章链接")
            return
        
        service_type = self.service_combo.currentText()
        if service_type == "元宝":
            article_urls = self.get_yuanbao_batch(self.current_url)
        else:
            article_urls = [self.current_url]
        
        self.status_bar.showMessage(f"正在打开{service_type}浏览器...")
        self.progress_bar.setRange(0, 0)
        
        self.browser_controller = BrowserController(service_type, article_urls)
        self.browser_controller.finished.connect(self.on_browser_open)
        self.browser_controller.error.connect(self.on_browser_error)
        self.browser_controller.start()
    
    def on_browser_open(self, message):
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(100)
        self.status_bar.showMessage(message)
        service = self.service_combo.currentText()
        extra = "（元宝已发送5个链接）" if service == "元宝" else ""
        QMessageBox.information(self, "提示", f"已成功打开{service}浏览器，问题已自动输入{extra}，请等待 AI 回复。")
    
    def on_browser_error(self, error_msg):
        QMessageBox.critical(self, "错误", error_msg)
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self.status_bar.showMessage("操作失败")
    
    def auto_summarize_all(self):
        """自动循环总结所有链接"""
        if not self.article_links:
            QMessageBox.warning(self, "警告", "没有可总结的链接")
            return
        
        service_type = self.service_combo.currentText()
        
        try:
            delay = int(self.delay_input.text())
        except ValueError:
            delay = 5
        
        self.auto_btn.setEnabled(False)
        self.summarize_btn.setEnabled(False)
        
        # 创建自动总结线程，传递延迟时间
        self.auto_thread = AutoSummarizeThread(service_type, self.article_links, delay)
        self.auto_thread.progress.connect(self.on_auto_progress)
        self.auto_thread.finished.connect(self.on_auto_finished)
        self.auto_thread.error.connect(self.on_auto_error)
        self.auto_thread.start()
    
    def on_auto_progress(self, progress):
        current, total, batch_links = progress
        self.progress_bar.setRange(0, total)
        self.progress_bar.setValue(current)
        self.status_bar.showMessage(f"自动总结中: {current}/{total}")
        
        if batch_links:
            self.url_display.setPlainText("\n".join(batch_links))
    
    def on_auto_finished(self):
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(100)
        self.status_bar.showMessage("自动总结完成")
        self.auto_btn.setEnabled(True)
        self.summarize_btn.setEnabled(True)
        QMessageBox.information(self, "完成", "所有链接已自动总结完毕")
    
    def on_auto_error(self, error_msg):
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self.status_bar.showMessage(f"自动总结失败: {error_msg}")
        self.auto_btn.setEnabled(True)
        self.summarize_btn.setEnabled(True)
        QMessageBox.critical(self, "错误", f"自动总结失败：{error_msg}")

class AutoSummarizeThread(QThread):
    progress = pyqtSignal(tuple)
    finished = pyqtSignal()
    error = pyqtSignal(str)
    
    def __init__(self, service_type, links, delay):
        super().__init__()
        self.service_type = service_type
        self.links = links
        self.delay = delay
        self.browser = None
        self.page = None
    
    def run(self):
        try:
            with sync_playwright() as p:
                self.browser = p.chromium.launch(headless=False, args=['--start-maximized'])
                self.page = self.browser.new_page()
                
                url = SERVICE_URLS.get(self.service_type, SERVICE_URLS["豆包"])
                self.page.goto(url, timeout=60000)
                time.sleep(5)
                
                if self.service_type == "元宝":
                    batches = chunk_links(self.links, YUANBAO_BATCH_SIZE)
                    total = len(batches)
                    for i, batch in enumerate(batches, 1):
                        self.progress.emit((i, total, batch))
                        self.enter_question(batch)
                else:
                    total = len(self.links)
                    for i, article_url in enumerate(self.links, 1):
                        self.progress.emit((i, total, [article_url]))
                        self.enter_question([article_url])
                
                self.browser.close()
                self.finished.emit()
        except Exception as e:
            if self.browser:
                self.browser.close()
            self.error.emit(str(e))
    
    def enter_question(self, article_urls):
        question = build_question(self.service_type, article_urls)
        
        try:
            print(f"填写内容 ({len(article_urls)} 个链接): {question[:80]}...")
            fill_and_send_message(self.page, self.service_type, question)
            
            print(f"等待 {self.delay} 秒...")
            time.sleep(self.delay)
            
            if self.service_type == "元宝":
                print("打开新对话...")
                self.page.goto(SERVICE_URLS["元宝"], timeout=60000)
            else:
                print("刷新页面...")
                self.page.reload()
            time.sleep(3)
            
        except Exception as e:
            print(f"输入问题失败：{e}")
            import traceback
            traceback.print_exc()

if __name__ == "__main__":
    app = QApplication(sys.argv)
    window = MainWindow()
    window.show()
    sys.exit(app.exec_())