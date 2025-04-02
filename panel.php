<?php
include 'header.php';
include 'menu.php';
?>

<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('导出文章'); ?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">
                <div class="typecho-list-operate clearfix">
                    <form method="post" action="<?php $options->index('/action/export'); ?>" target="_blank" class="operate-form">
                        <div class="operate">
                            <button type="submit" class="btn primary" id="export-button">
                                <?php _e('导出所有文章'); ?>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="typecho-list-operate clearfix">
                    <p class="description">
                        <?php _e('说明: 点击导出按钮后将自动开始下载文章压缩包。'); ?>
                    </p>
                </div>
                <div id="export-status" style="display:none;">
                    <p class="message success">
                        <?php _e('正在准备导出文件，请等待下载开始...'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelector('.operate-form').addEventListener('submit', function(e) {
    // 显示状态信息
    var statusDiv = document.getElementById('export-status');
    var button = document.getElementById('export-button');
    
    statusDiv.style.display = 'block';
    button.disabled = true;
    button.innerHTML = '<?php _e('正在导出...'); ?>';

    // 15秒后重置按钮状态，但不显示错误消息
    setTimeout(function() {
        button.disabled = false;
        button.innerHTML = '<?php _e('导出所有文章'); ?>';
        statusDiv.style.display = 'none';
    }, 15000);

    // 不阻止表单提交
    return true;
});
</script>

<?php
include 'footer.php';
?>