jQuery(document).ready(function ($) {
    var importForm = $('#wai-importer-form');
    var submitButton = importForm.find('.wai-submit-button');
    var feedbackDiv = $('#wai-feedback');

    var taskData = {};
    var REQUEST_DELAY = 500;
    var IMAGE_REQUEST_MAX_RETRIES = 2;
    var IMAGE_REQUEST_RETRY_DELAY = 3000;
    var strings = (window.wai_ajax && window.wai_ajax.i18n) || {};

    function i18n(key, fallback) {
        return Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : fallback;
    }

    function formatString(template, values) {
        return template.replace(/%(\d+\$)?s/g, function (match, position) {
            var index = position ? parseInt(position, 10) - 1 : 0;
            var value = values[index];
            if (!position) {
                values = values.slice(1);
            }
            return value === undefined || value === null ? '' : String(value);
        });
    }

    function safeUrl(url) {
        var parser = document.createElement('a');
        parser.href = String(url || '');
        return parser.protocol === 'http:' || parser.protocol === 'https:' ? parser.href : '#';
    }

    function showTextNotice(type, message) {
        feedbackDiv.empty().append(
            $('<div>').addClass('notice notice-' + type).append(
                $('<p>').text(message)
            )
        );
    }

    function showProgressNotice(message, percentComplete) {
        var safePercent = Math.max(0, Math.min(100, Number(percentComplete) || 0));
        var progressBar = $('<div>').css({
            width: '100%',
            background: '#eee',
            borderRadius: '3px',
            overflow: 'hidden'
        }).append(
            $('<div>').css({
                width: safePercent + '%',
                background: '#0073aa',
                height: '10px'
            })
        );

        feedbackDiv.empty().append(
            $('<div>').addClass('notice notice-info')
                .append($('<p>').text(message))
                .append(progressBar)
        );
    }

    function appendTemplateWithLink(container, template, linkText, href) {
        var placeholderIndex = template.indexOf('%s');
        var link = $('<a>')
            .attr('href', safeUrl(href))
            .attr('target', '_blank')
            .attr('rel', 'noopener noreferrer')
            .text(linkText);

        if (placeholderIndex === -1) {
            container.append(document.createTextNode(template + ' ')).append(link);
            return;
        }

        container
            .append(document.createTextNode(template.slice(0, placeholderIndex)))
            .append(link)
            .append(document.createTextNode(template.slice(placeholderIndex + 2)));
    }

    function recordSkippedImage(imageUrl, reason) {
        if (!taskData.skipped_images) {
            taskData.skipped_images = [];
        }

        taskData.skipped_images.push({
            image_url: imageUrl,
            reason: reason || i18n('image_download_server_error', 'A server error occurred while downloading this image.')
        });
    }

    importForm.on('submit', function (event) {
        event.preventDefault();

        var wechatUrl = $('#wechat_url').val();
        var generateThumbnails = $('#wai_gen_thumbs').is(':checked');

        if (wechatUrl === '' || !wechatUrl.startsWith('https://mp.weixin.qq.com/')) {
            showTextNotice('error', i18n('invalid_url', 'Please enter a valid WeChat article URL.'));
            return;
        }

        startImportProcess(wechatUrl, generateThumbnails);
    });


    function startImportProcess(wechatUrl, generateThumbnails) {
        submitButton.prop('disabled', true).val(i18n('parsing_article', 'Parsing article...'));
        showTextNotice('info', i18n('step_1_message', 'Step 1/3: Parsing the article structure. Please wait...'));
        $('.notice-success, .notice-error').remove();

        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wai_start_import',
                _ajax_nonce: wai_ajax.nonce,
                wechat_url: wechatUrl
            },
            success: function (response) {
                if (response.success) {
                    taskData = {
                        task_id: response.data.task_id,
                        image_urls: response.data.image_urls,
                        total_images: response.data.image_urls.length,
                        processed_count: 0,
                        generate_thumbnails: generateThumbnails,
                        image_retry_counts: {},
                        skipped_images: []
                    };
                    if (taskData.total_images > 0) {
                        processNextImage();
                    } else {
                        finishImportProcess();
                    }
                } else {
                    handleError(response.data.error || i18n('parse_article_failed', 'Failed to parse the article.'));
                }
            },
            error: function (jqXHR) {
                handleError(i18n('parse_server_error', 'A server error occurred while parsing the article.'), jqXHR);
            }
        });
    }


    function processNextImage() {
        if (taskData.processed_count >= taskData.total_images) {
            finishImportProcess();
            return;
        }

        var currentImageUrl = taskData.image_urls[taskData.processed_count];
        var progressText = (taskData.processed_count + 1) + ' / ' + taskData.total_images;

        var statusText = taskData.generate_thumbnails ? i18n('download_and_process_image', 'Download and process image') : i18n('quick_download_image', 'Fast image download');
        submitButton.val(formatString(i18n('progress_button_label', '%1$s (%2$s)'), [statusText, progressText]));
        showProgressNotice(
            formatString(i18n('step_2_message', 'Step 2/3: %1$s %2$s. Please keep this page open...'), [statusText, progressText]),
            (taskData.processed_count + 1) / taskData.total_images * 100
        );

        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wai_process_image',
                _ajax_nonce: wai_ajax.nonce,
                task_id: taskData.task_id,
                image_url: currentImageUrl,
                generate_thumbnails: taskData.generate_thumbnails
            },
            success: function (response) {
                if (response.success) {
                    taskData.processed_count++;
                    setTimeout(processNextImage, REQUEST_DELAY);
                } else {
                    recordSkippedImage(
                        currentImageUrl,
                        response.data && response.data.error ? response.data.error : i18n('image_download_server_error', 'A server error occurred while downloading this image.')
                    );
                    console.warn('Skipping image due to error:', taskData.skipped_images[taskData.skipped_images.length - 1]);
                    taskData.processed_count++;

                    setTimeout(processNextImage, REQUEST_DELAY);
                }
            },
            error: function (jqXHR) {
                var retryCount = taskData.image_retry_counts[currentImageUrl] || 0;
                if (retryCount < IMAGE_REQUEST_MAX_RETRIES) {
                    taskData.image_retry_counts[currentImageUrl] = retryCount + 1;
                    console.warn('Retrying image after server/transport error:', {
                        image_url: currentImageUrl,
                        retry: retryCount + 1,
                        status: jqXHR.status
                    });
                    setTimeout(processNextImage, IMAGE_REQUEST_RETRY_DELAY * (retryCount + 1));
                    return;
                }

                console.warn('Skipping image after repeated server/transport errors:', {
                    image_url: currentImageUrl,
                    status: jqXHR.status
                });
                recordSkippedImage(
                    currentImageUrl,
                    i18n('image_download_server_error', 'A server error occurred while downloading this image.')
                );
                taskData.processed_count++;
                setTimeout(processNextImage, REQUEST_DELAY);
            }
        });
    }

    function finishImportProcess() {
        submitButton.val(i18n('creating_post', 'Creating post...'));
        showTextNotice('info', i18n('step_3_message', 'Step 3/3: Images are processed. Finalizing and creating the post...'));
        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'wai_finish_import',
                _ajax_nonce: wai_ajax.nonce,
                task_id: taskData.task_id
            },
            success: function (response) {
                if (response.success) {
                    var successParagraph = $('<p>');
                    appendTemplateWithLink(
                        successParagraph,
                        i18n('imported_as_draft_template', 'The article was imported as a draft. %s'),
                        i18n('view_or_edit', 'View or edit it here'),
                        response.data.edit_link
                    );

                    var skippedImages = taskData.skipped_images || [];
                    var notice = $('<div>').addClass('notice notice-' + (skippedImages.length ? 'warning' : 'success')).append(successParagraph);
                    if (skippedImages.length) {
                        notice.append(
                            $('<p>').text(
                                formatString(
                                    i18n('imported_with_skipped_images_template', 'The article was imported as a draft, but %s images could not be downloaded.'),
                                    [skippedImages.length]
                                )
                            )
                        );

                        var skippedDetails = $('<details>').append(
                            $('<summary>').text(i18n('skipped_image_urls_heading', 'Skipped image URLs'))
                        );
                        var skippedList = $('<ul>');
                        skippedImages.forEach(function (skippedImage) {
                            skippedList.append(
                                $('<li>')
                                    .append(
                                        $('<a>')
                                            .attr('href', safeUrl(skippedImage.image_url))
                                            .attr('target', '_blank')
                                            .attr('rel', 'noopener noreferrer')
                                            .text(skippedImage.image_url)
                                    )
                                    .append(document.createTextNode(skippedImage.reason ? ' — ' + skippedImage.reason : ''))
                            );
                        });
                        notice.append(skippedDetails.append(skippedList));
                        console.warn('Import completed with skipped images:', skippedImages);
                    }

                    feedbackDiv.empty().append(
                        notice
                    );
                    $('#wechat_url').val('');
                } else {
                    handleError(response.data.error || i18n('create_post_failed', 'Failed to create the post.'));
                }
            },
            error: function (jqXHR) {
                handleError(i18n('create_post_server_error', 'A server error occurred while creating the post.'), jqXHR);
            },
            complete: function() {
                submitButton.prop('disabled', false).val(i18n('start_import', 'Start Import'));
            }
        });
    }

    function handleError(errorMessage, jqXHR) {
        var paragraph = $('<p>').text(
            formatString(i18n('import_failed_message', 'Import failed: %s'), [errorMessage])
        );

        if (jqXHR && jqXHR.responseText) {
            var responseText = jqXHR.responseText;
            var phpErrorMatch = responseText.match(/<b>(Fatal error|Parse error|Warning)<\/b>:(.*?) in <b>(.*?)<\/b> on line <b>(\d+)<\/b>/);
            var detailTemplate = phpErrorMatch ?
                i18n('server_debug_info_message', 'Server debug information: %s') :
                i18n('partial_server_response', 'Partial server response: %s');
            var detailText = phpErrorMatch ? phpErrorMatch[0] : responseText.substring(0, 300);

            paragraph
                .append($('<br>'))
                .append($('<small>').text(formatString(detailTemplate, [detailText])));
        }

        feedbackDiv.empty().append(
            $('<div>').addClass('notice notice-error').append(paragraph)
        );
        submitButton.prop('disabled', false).val(i18n('start_import', 'Start Import'));
    }
});
