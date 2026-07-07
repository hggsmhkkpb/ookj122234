import sys
import random
import time
from PyQt5.QtWidgets import (
    QApplication, QMainWindow, QWidget, QVBoxLayout, QHBoxLayout,
    QPushButton, QLabel, QProgressBar, QTextEdit, QFileDialog, QMessageBox
)
from PyQt5.QtCore import Qt, QThread, pyqtSignal

from main import SitemapParser

class ParserThread(QThread):
    finished = pyqtSignal(list, float)
    error = pyqtSignal(str)
    
    def __init__(self, file_path):
        super().__init__()
        self.file_path = file_path
    
    def run(self):
        try:
            start = time.time()
            links = SitemapParser.extract_links_from_file(self.file_path)
            elapsed = time.time() - start
            self.finished.emit(links, elapsed)
        except Exception as e:
            self.error.emit(str(e))

class TestWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("XML解析测试工具")
        self.setGeometry(100, 100, 800, 600)
        self.init_ui()
    
    def init_ui(self):
        central_widget = QWidget()
        self.setCentralWidget(central_widget)
        layout = QVBoxLayout(central_widget)
        
        # 选择文件区域
        file_layout = QHBoxLayout()
        self.file_label = QLabel("未选择文件")
        self.browse_btn = QPushButton("选择XML文件")
        self.browse_btn.clicked.connect(self.browse_file)
        self.parse_btn = QPushButton("开始解析")
        self.parse_btn.clicked.connect(self.start_parse)
        self.parse_btn.setEnabled(False)
        
        file_layout.addWidget(self.file_label)
        file_layout.addWidget(self.browse_btn)
        file_layout.addWidget(self.parse_btn)
        layout.addLayout(file_layout)
        
        # 进度条
        self.progress_bar = QProgressBar()
        layout.addWidget(self.progress_bar)
        
        # 结果显示
        result_layout = QHBoxLayout()
        
        # 链接列表
        links_group = QWidget()
        links_layout = QVBoxLayout(links_group)
        links_layout.addWidget(QLabel("解析结果（链接列表）"))
        self.links_text = QTextEdit()
        self.links_text.setReadOnly(True)
        links_layout.addWidget(self.links_text)
        result_layout.addWidget(links_group)
        
        # 统计信息
        stats_group = QWidget()
        stats_layout = QVBoxLayout(stats_group)
        self.stats_label = QLabel("统计信息：")
        self.time_label = QLabel("解析时间：")
        self.count_label = QLabel("链接数量：")
        stats_layout.addWidget(self.stats_label)
        stats_layout.addWidget(self.time_label)
        stats_layout.addWidget(self.count_label)
        stats_layout.addStretch()
        result_layout.addWidget(stats_group)
        
        layout.addLayout(result_layout)
        
        self.selected_file = ""
    
    def browse_file(self):
        file_path, _ = QFileDialog.getOpenFileName(
            self, "选择XML文件", "", "XML文件 (*.xml);;所有文件 (*.*)"
        )
        if file_path:
            self.selected_file = file_path
            self.file_label.setText(f"已选择: {file_path}")
            self.parse_btn.setEnabled(True)
            self.links_text.clear()
            self.time_label.setText("解析时间：")
            self.count_label.setText("链接数量：")
    
    def start_parse(self):
        if not self.selected_file:
            QMessageBox.warning(self, "警告", "请先选择文件")
            return
        
        self.progress_bar.setRange(0, 0)
        self.parse_btn.setEnabled(False)
        self.links_text.clear()
        
        self.parser = ParserThread(self.selected_file)
        self.parser.finished.connect(self.on_parse_finished)
        self.parser.error.connect(self.on_parse_error)
        self.parser.start()
    
    def on_parse_finished(self, links, elapsed):
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(100)
        self.parse_btn.setEnabled(True)
        
        self.time_label.setText(f"解析时间：{elapsed:.4f}秒")
        self.count_label.setText(f"链接数量：{len(links)}个")
        
        # 显示前50个链接
        display_text = "\n".join(links[:50])
        if len(links) > 50:
            display_text += f"\n...\n（共 {len(links)} 个链接，仅显示前50个）"
        self.links_text.setPlainText(display_text)
    
    def on_parse_error(self, error_msg):
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self.parse_btn.setEnabled(True)
        QMessageBox.critical(self, "错误", f"解析失败：{error_msg}")

if __name__ == "__main__":
    app = QApplication(sys.argv)
    window = TestWindow()
    window.show()
    sys.exit(app.exec_())