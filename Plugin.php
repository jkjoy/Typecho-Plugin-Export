<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 *  
 * 导出文章为可自定义的Yaml格式的markdown文件
 * @package Export
 * @author jkjoy
 * @url https://github.com/jkjoy
 * @version 1.0.0
 */
class Export_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Helper::addPanel(1, 'Export/panel.php', _t('导出文章'), _t('导出文章'), 'administrator');
        Helper::addAction('export', 'Export_Action');
        return _t('插件已经激活');
    }

    public static function deactivate()
    {
        Helper::removePanel(1, 'Export/panel.php');
        Helper::removeAction('export');
        return _t('插件已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 字段显示配置
        $fieldMappings = array(
            'author' => array('文章作者', 'author'),
            'pubDatetime' => array('发布时间', 'pubDatetime'),
            'modDatetime' => array('修改时间', 'modDatetime'),
            'title' => array('文章标题', 'title'),
            'slug' => array('文章别名', 'slug'),
            'category' => array('文章分类', 'category'),
            'tags' => array('标签', 'tags'),
            'description' => array('描述', 'description')
        );

        // 为每个字段创建配置输入框
        foreach ($fieldMappings as $field => $labels) {
            $element = new Typecho_Widget_Helper_Form_Element_Text(
                'field_' . $field,
                null,
                $labels[1],  // 默认值使用第二个元素
                _t($labels[0] . ' 字段名称'),
                _t('留空则不导出该字段。自定义导出时 ' . $labels[0] . ' 的字段名称')
            );
            $form->addInput($element);
        }

        // 是否包含默认标签
        $defaultTags = new Typecho_Widget_Helper_Form_Element_Radio(
            'defaultTags',
            array(
                0 => _t('不包含'),
                1 => _t('包含 docs 标签')
            ),
            0,
            _t('无标签文章是否包含默认标签'),
            _t('当文章没有标签时，是否添加默认的 docs 标签')
        );
        $form->addInput($defaultTags);

        // draft 的默认值
        $defaultDraft = new Typecho_Widget_Helper_Form_Element_Radio(
            'defaultDraft',
            array(
                '' => _t('不包含'),
                'true' => _t('true'),
                'false' => _t('false')
            ),
            'false',
            _t('draft 字段的默认值'),
            _t('选择 draft 字段的默认值，选择不包含则不输出该字段')
        );
        $form->addInput($defaultDraft);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }
}