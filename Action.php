<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class Export_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $options;
    
    public function execute()
    {
        if (!$this->widget('Widget_User')->hasLogin()) {
            $this->response->redirect($this->options->loginUrl);
        }
        $this->options = Helper::options()->plugin('Export');
    }

    public function prepare($request)
    {
        $this->request = $request;
    }

    public function action()
    {
        try {
            // 检查用户权限
            $user = $this->widget('Widget_User');
            if (!$user->hasLogin()) {
                throw new Typecho_Widget_Exception(_t('未登录'), 403);
            }

            // 创建临时目录
            $tempDir = sys_get_temp_dir() . '/typecho_export_' . uniqid();
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            // 获取文章列表
            $db = Typecho_Db::get();
            $posts = $db->fetchAll($db->select()->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->order('created', Typecho_Db::SORT_DESC));

            if (empty($posts)) {
                throw new Exception(_t('没有找到可导出的文章'));
            }

            // 导出文章
            foreach ($posts as $post) {
                $content = $this->formatPost($post);
                $filename = $tempDir . '/' . $this->getSafeFilename($post['slug'] ?: $post['title']) . '.md';
                if (file_put_contents($filename, $content) === false) {
                    throw new Exception(_t('写入文件失败：') . $filename);
                }
            }

            // 创建ZIP文件
            $zipFile = $tempDir . '/posts.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception(_t('创建ZIP文件失败'));
            }

            // 添加文件到ZIP
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tempDir),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir() && $file->getFilename() != 'posts.zip') {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($tempDir) + 1);
                    if (!$zip->addFile($filePath, $relativePath)) {
                        throw new Exception(_t('添加文件到ZIP失败：') . $relativePath);
                    }
                }
            }
            $zip->close();

            // 确保文件存在
            if (!file_exists($zipFile)) {
                throw new Exception(_t('ZIP文件创建失败'));
            }

            // 清除所有已有的输出
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // 阻止浏览器缓存
            header('Content-Type: application/octet-stream');
            header('Content-Transfer-Encoding: binary');
            header('Content-Disposition: attachment; filename="posts_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . filesize($zipFile));
            header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            // 输出文件内容
            if ($fileHandle = fopen($zipFile, 'rb')) {
                while (!feof($fileHandle) && !connection_aborted()) {
                    echo fread($fileHandle, 8192);
                    flush();
                }
                fclose($fileHandle);
            }

            // 清理临时文件
            $this->removeDirectory($tempDir);
            exit();

        } catch (Exception $e) {
            // 清理临时文件
            if (isset($tempDir) && is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }

            // 返回错误信息
            $this->widget('Widget_Notice')->set('error', _t('导出失败：') . $e->getMessage());
            $this->response->redirect(Typecho_Common::url('extending.php?panel=Export%2Fpanel.php', $this->options->adminUrl));
        }
    }

    /**
     * 格式化文章内容
     */
    private function formatPost($post)
{
    $postWidget = Typecho_Widget::widget('Widget_Abstract_Contents@temp_' . $post['cid']);
    $postWidget->push($post);
    
    // 初始化前置数据数组
    $frontMatter = array();

    // 获取各字段的自定义名称并添加非空值
    $fields = array(
        'author' => $postWidget->author->screenName,
        'pubDatetime' => gmdate('Y-m-d\TH:i:00\Z', $post['created']),
        'modDatetime' => gmdate('Y-m-d\TH:i:00.619\Z', $post['modified']),
        'title' => str_replace(array("\r", "\n"), '', $post['title']),
        'slug' => !empty($post['slug']) ? $post['slug'] : Typecho_Common::slugName($post['title'])
    );

    // 只添加配置了字段名的非空值
    foreach ($fields as $field => $value) {
        $fieldName = $this->getFieldName($field);
        if ($fieldName && !empty($value)) {
            $frontMatter[$fieldName] = $value;
        }
    }

    // 处理文章分类
    $categoryField = $this->getFieldName('category');
    if ($categoryField) {
        $categories = $this->getCategories($post['cid']);
        if (!empty($categories)) {
            $frontMatter[$categoryField] = $categories;
        }
    }

    // 处理标签
    $tagsField = $this->getFieldName('tags');
    if ($tagsField) {
        $tags = $this->getTags($post['cid']);
        if (!empty($tags) || $this->options->defaultTags == 1) {
            $frontMatter[$tagsField] = !empty($tags) ? $tags : ['docs'];
        }
    }

    // 处理文章摘要
    $descriptionField = $this->getFieldName('description');
    if ($descriptionField) {
        // 首先尝试获取自定义字段summary
        $summary = $this->getCustomField($post['cid'], 'summary');
        
        if (empty($summary)) {
            // 如果没有summary，则使用文章内容前200字
            $content = strip_tags($post['text']);
            $summary = mb_substr($content, 0, 200, 'UTF-8');
            $summary = str_replace(array("\r", "\n"), ' ', $summary);
        }
        
        if (!empty($summary)) {
            $frontMatter[$descriptionField] = $summary;
        }
    }

    // 添加draft字段（如果配置了的话）
    if (!empty($this->options->defaultDraft)) {
        $frontMatter['draft'] = $this->options->defaultDraft;
    }

    // 构建输出
    $output = "---\n";
    
    // 输出 front matter
    foreach ($frontMatter as $key => $value) {
        if (is_array($value)) {
            $output .= "{$key}:\n";
            foreach ($value as $item) {
                $output .= "  - {$item}\n";
            }
        } else {
            $value = str_replace('"', '\"', $value); // 转义引号
            $output .= "{$key}: \"{$value}\"\n";
        }
    }
    
    $output .= "---\n\n";
    
    // 清除 <!--markdown--> 标记并使用原始内容
    $content = $post['text'];
    $content = str_replace('<!--markdown-->', '', $content);
    $content = trim($content); // 移除可能产生的多余空行
    
    $output .= $content;

    return $output;
}

    /**
     * 获取字段名称
     */
    private function getFieldName($field)
    {
        $fieldName = $this->options->{'field_' . $field};
        return empty($fieldName) ? '' : $fieldName;
    }

    /**
     * 获取文章分类
     */
    private function getCategories($cid)
    {
        $db = Typecho_Db::get();
        $categories = $db->fetchAll($db->select()->from('table.metas')
            ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'category'));
        
        $categoryNames = array();
        foreach ($categories as $category) {
            $categoryNames[] = $category['name'];
        }
        
        return $categoryNames;
    }

    /**
     * 获取文章标签
     */
    private function getTags($cid)
    {
        $db = Typecho_Db::get();
        $tags = $db->fetchAll($db->select()->from('table.metas')
            ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'tag'));
        
        $tagNames = array();
        foreach ($tags as $tag) {
            $tagNames[] = $tag['name'];
        }
        
        return $tagNames;
    }

    /**
     * 获取自定义字段
     */
    private function getCustomField($cid, $name)
    {
        $db = Typecho_Db::get();
        $field = $db->fetchRow($db->select()->from('table.fields')
            ->where('cid = ?', $cid)
            ->where('name = ?', $name));
        
        return $field ? $field['str_value'] : '';
    }

    /**
     * 获取安全的文件名
     */
    private function getSafeFilename($filename)
    {
        // 移除或替换不安全的字符
        $filename = preg_replace('/[\/\\\:\*\?\"\<\>\|]/', '-', $filename);
        $filename = str_replace(' ', '-', $filename);
        return trim($filename, '-');
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        return rmdir($dir);
    }
}