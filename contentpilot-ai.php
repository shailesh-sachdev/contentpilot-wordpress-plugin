<?php
/*
Plugin Name: ContentPilot AI
Description: Generate and publish AI-powered blog posts from your backend.
Version: 1.0
Author: Shailesh Sachdev
*/

add_action('admin_menu', 'contentpilot_ai_menu');
function contentpilot_ai_menu() {
    add_menu_page(
        'ContentPilot AI',
        'ContentPilot AI',
        'manage_options',
        'contentpilot-ai',
        'contentpilot_ai_render_page'
    );
    add_submenu_page(
        'contentpilot-ai',
        'Scheduled AI Posts',
        'Scheduled AI Posts',
        'manage_options',
        'contentpilot-ai-scheduled',
        'contentpilot_ai_render_scheduled_page'
    );
    add_submenu_page(
        'contentpilot-ai',
        'Keyword Planner',
        'Keyword Planner',
        'manage_options',
        'contentpilot-ai-keyword-planner',
        'contentpilot_ai_render_keyword_planner_page'
    );
}

// Activation DB logic: robust migration
register_activation_hook(__FILE__, 'contentpilot_create_keywords_table');
function contentpilot_create_keywords_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'contentpilot_keywords';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        keyword VARCHAR(255) NOT NULL,
        user_id BIGINT(20) UNSIGNED,
        added DATETIME DEFAULT CURRENT_TIMESTAMP,
        post_id BIGINT(20) NULL DEFAULT NULL,
        error_log TEXT NULL DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    // Migrate: add columns if missing
    $columns = $wpdb->get_col("DESC $table", 0);
    if (!in_array('post_id', $columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN post_id BIGINT(20) NULL DEFAULT NULL;");
    }
    if (!in_array('error_log', $columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN error_log TEXT NULL DEFAULT NULL;");
    }
}

// Minimal page render to avoid undefined callback errors
function contentpilot_ai_render_page() {
    ?>
    <div class="wrap">
        <h1>ContentPilot AI</h1>
        <form id="contentpilot-ai-form">
            <label for="ai_prompt">Enter your blog prompt:</label><br>
            <textarea id="ai_prompt" name="ai_prompt" rows="4" cols="50"></textarea><br>
            <label for="ai_publish_date">Publish Date & Time (YYYY-MM-DD HH:MM):</label><br>
            <input type="datetime-local" id="ai_publish_date" name="ai_publish_date"><br>
            <button type="button" class="button button-primary" onclick="contentpilot_submit_prompt()">Generate Blog</button>
        </form>
        <div id="ai_content_result" style="margin-top: 20px;"></div>
    </div>
    <script>
    var contentpilot_current_ai_data = null;
    var contentpilot_current_publish_date = null;

    function contentpilot_submit_prompt() {
        contentpilot_current_ai_data = null;
        contentpilot_current_publish_date = document.getElementById('ai_publish_date').value;
        var prompt = document.getElementById('ai_prompt').value;
        var resultDiv = document.getElementById('ai_content_result');
        resultDiv.innerHTML = 'Generating content...';
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=contentpilot_generate_blog&prompt=' + encodeURIComponent(prompt)
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                let d = data.data;
                contentpilot_current_ai_data = d;
                resultDiv.innerHTML =
                    '<h3>Title:</h3><div><strong>' + (d.title || '') + '</strong></div>' +
                    (d.meta_description ? '<h3>Meta Description:</h3><div>' + d.meta_description + '</div>' : '') +
                    (d.featured_image_url ? '<h3>Featured Image:</h3><div><img src="' + d.featured_image_url + '" style="max-width:300px"/></div>' : '') +
                    '<h3>Content:</h3><div style="background:#f5f5f5;padding:10px;">' + (d.content || '') + '</div>' +
                    '<button type="button" class="button button-primary" onclick="contentpilot_publish_post()">Publish as Post</button>';
            } else {
                resultDiv.innerHTML =
                    '<strong style="color:red;">Error: ' + (data.data || 'Unknown error') + '</strong>';
            }
        });
    }

    function contentpilot_publish_post() {
        var resultDiv = document.getElementById('ai_content_result');
        resultDiv.innerHTML = 'Publishing post...';
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=contentpilot_publish_post'
                + '&content=' + encodeURIComponent(JSON.stringify(contentpilot_current_ai_data))
                + '&publish_date=' + encodeURIComponent(contentpilot_current_publish_date)
        })
        .then(response => response.json())
        .then(data => {
            if(data.success && data.data && data.data.post_url) {
                resultDiv.innerHTML = 'Post published! <a href="'+data.data.post_url+'" target="_blank">View it here</a>';
            } else {
                resultDiv.innerHTML = '<strong style="color:red;">Error publishing: Invalid or missing URL.</strong>';
            }
        });
    }
    </script>
    <?php
}

// Render keyword planner page
function contentpilot_ai_render_keyword_planner_page() {
    ?>
    <div class="wrap">
        <h1>AI Keyword Planner</h1>
        <h2 class="nav-tab-wrapper">
            <a href="#" class="nav-tab nav-tab-active" id="tab_ideas" onclick="contentpilot_switch_keyword_tab('ideas'); return false;">Keyword Ideas</a>
            <a href="#" class="nav-tab" id="tab_saved" onclick="contentpilot_switch_keyword_tab('saved'); return false;">Saved Keywords</a>
        </h2>
        <div id="keyword_tab_ideas" style="display:block;">
            <label>
                <input type="radio" name="seo_experience" value="new" checked onchange="contentpilot_switch_keyword_form()"> New to SEO
            </label>
            <label>
                <input type="radio" name="seo_experience" value="experienced" onchange="contentpilot_switch_keyword_form()"> Experienced SEO
            </label>
            <div id="keyword_planner_form_container"></div>
            <div id="keyword_ai_result" style="margin-top:20px"></div>
        </div>
        <div id="keyword_tab_saved" style="display:none;">
            <div id="keyword_frequency_section" style="margin-bottom:18px;">
                <label><strong>How frequently do you want to post blogs to rank for these keywords?</strong></label><br>
                <select id="post_frequency_select" onchange="contentpilot_on_frequency_change()">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="custom">Custom</option>
                </select>
                <div id="custom_days_field" style="display:none;margin-top:8px;">
                    <label>Post every <input type="number" id="custom_days_input" min="1" style="width:60px"> days</label>
                </div>
                <button class="button" style="margin-top:8px;" onclick="contentpilot_save_post_frequency()">Save Frequency</button>
                <div id="keyword_frequency_status" style="margin-top:8px;color:green;font-weight:bold;"></div>
            </div>
            <div id="keyword_saved_table"></div>
        </div>
    </div>
    <script>
        function contentpilot_switch_keyword_form() {
            var exp = document.querySelector('input[name="seo_experience"]:checked').value;
            var container = document.getElementById('keyword_planner_form_container');
            if (exp === 'new') {
                container.innerHTML = `
                    <form id="contentpilot-keyword-form" onsubmit="contentpilot_submit_keyword_form(event)">
                        <label>Business Name:</label><br>
                        <input type="text" name="business_name" required><br>
                        <label>Products/Services offered:</label><br>
                        <input type="text" name="services" required><br>
                        <label>Location:</label><br>
                        <input type="text" name="location"><br>
                        <label>Describe your ideal customers:</label><br>
                        <input type="text" name="customers"><br>
                        <label>What problems do you solve?</label><br>
                        <input type="text" name="problems"><br>
                        <label>What makes you unique?</label><br>
                        <input type="text" name="unique"><br>
                        <label>Common customer phrases/terms:</label><br>
                        <input type="text" name="customer_phrases"><br>
                        <label>Top competitors:</label><br>
                        <input type="text" name="competitors"><br>
                        <label>Topics/content you want to be known for:</label><br>
                        <input type="text" name="topics"><br>
                        <label>Seasonal/trending factors:</label><br>
                        <input type="text" name="trends"><br>
                        <button class="button button-primary" type="submit">Find Best Keywords</button>
                    </form>
                `;
            } else {
                container.innerHTML = `<div>Coming soon! Advanced keyword inputs for pros.</div>`;
            }
            document.getElementById('keyword_ai_result').innerHTML = '';
        }
        contentpilot_switch_keyword_form(); // Initial render
            function contentpilot_submit_keyword_form(e) {
            e.preventDefault();
            var form = document.getElementById('contentpilot-keyword-form');
            var formData = new FormData(form);
            var postData = {};
            formData.forEach((value, key) => postData[key] = value);
            document.getElementById('keyword_ai_result').innerHTML = 'Finding best keywords...';
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=contentpilot_ai_keyword_planner&data=' + encodeURIComponent(JSON.stringify(postData))
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    renderKeywordsWithCheckboxes(data.data);
                } else {
                    document.getElementById('keyword_ai_result').innerHTML = '<strong style="color:red;">Error: ' + data.data + '</strong>';
                }
            });
        }
        function renderKeywordsWithCheckboxes(tableHTML) {
            let tempDiv = document.createElement('div');
            tempDiv.innerHTML = tableHTML;
            let table = tempDiv.querySelector('table');
            if (!table) {
                document.getElementById('keyword_ai_result').innerHTML = 'No valid keyword table found!';
                return;
            }

            // Insert a checkbox header
            let headerRow = table.querySelector('tr');
            let th = document.createElement('th');
            th.innerText = 'Select';
            headerRow.insertBefore(th, headerRow.firstChild);

            // Add checkboxes for each keyword row
            table.querySelectorAll('tr').forEach((row, idx) => {
                if (idx === 0) return; // Skip header

                // After adding checkbox header, column 1 is always Keyword
                let keywordCell = row.children[0] ? row.children[0].innerText.trim() : '';
                let td = document.createElement('td');
                td.innerHTML = `<input type="checkbox" class="keyword_checkbox" data-keyword="${keywordCell.replace(/"/g, '&quot;')}">`;
                row.insertBefore(td, row.firstChild);
            });


            let resultDiv = document.getElementById('keyword_ai_result');
            resultDiv.innerHTML = '';
            resultDiv.appendChild(table);

            // Save button
            let saveBtn = document.createElement('button');
            saveBtn.className = "button button-primary";
            saveBtn.innerText = "Save Selected Keywords";
            saveBtn.onclick = contentpilot_save_selected_keywords;
            resultDiv.appendChild(saveBtn);

            // Area for saved keywords below
            let savedDiv = document.getElementById('keyword_saved_keywords');
            if (!savedDiv) {
                savedDiv = document.createElement('div');
                savedDiv.id = "keyword_saved_keywords";
                resultDiv.appendChild(savedDiv);
            }
            contentpilot_load_saved_keywords();
        }
        function contentpilot_save_selected_keywords() {
            let checked = Array.from(document.querySelectorAll('.keyword_checkbox:checked'));
            let keywords = checked.map(box => {
                // prefer data-keyword attribute; fallback to value if present
                const kw = box.dataset && box.dataset.keyword ? box.dataset.keyword : box.getAttribute('data-keyword') || box.value || '';
                return kw.trim();
            });

            if(keywords.length == 0) {
                alert('Please select at least one keyword to save.');
                return;
            }

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=contentpilot_save_keywords&keywords=' + encodeURIComponent(JSON.stringify(keywords))
            })
            .then(response => response.json())
            .then(data => {
                if(data.success) {
                    contentpilot_load_saved_keywords();
                } else {
                    alert('Error saving keywords: ' + data.data);
                }
            });
        }
    function contentpilot_switch_keyword_tab(tab) {
        document.getElementById("tab_ideas").classList.remove("nav-tab-active");
        document.getElementById("tab_saved").classList.remove("nav-tab-active");
        document.getElementById("keyword_tab_ideas").style.display = "none";
        document.getElementById("keyword_tab_saved").style.display = "none";
        if (tab === "ideas") {
            document.getElementById("tab_ideas").classList.add("nav-tab-active");
            document.getElementById("keyword_tab_ideas").style.display = "block";
        } else {
            document.getElementById("tab_saved").classList.add("nav-tab-active");
            document.getElementById("keyword_tab_saved").style.display = "block";
            contentpilot_load_saved_keywords_table();
            contentpilot_load_frequency_prefill();
        }
    }
    contentpilot_switch_keyword_tab('ideas');

    function contentpilot_on_frequency_change() {
        var freq = document.getElementById('post_frequency_select').value;
        var customDiv = document.getElementById('custom_days_field');
        if(freq === 'custom') {
            customDiv.style.display = '';
        } else {
            customDiv.style.display = 'none';
        }
    }

    function contentpilot_save_post_frequency() {
        var freq = document.getElementById('post_frequency_select').value;
        var days = '';
        if(freq === 'custom') {
            days = document.getElementById('custom_days_input').value;
            if(!days || isNaN(days) || days < 1) {
                alert('Please enter a valid number of days for custom frequency.');
                return;
            }
        }
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=contentpilot_save_post_frequency&freq=' + encodeURIComponent(freq) + '&days=' + encodeURIComponent(days)
        })
        .then(response => response.json())
        .then(data => {
            var statusDiv = document.getElementById('keyword_frequency_status');
            if(data.success) {
                statusDiv.innerText = 'Saved!';
                statusDiv.style.color = "green";
            } else {
                statusDiv.style.color = "red";
                statusDiv.innerText = data.data;
            }
            setTimeout(() => { statusDiv.innerText = ''; }, 3000);
        });
    }

    // Pre-fill frequency from server on tab load
    function contentpilot_load_frequency_prefill() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=contentpilot_get_post_frequency')
        .then(response => response.json())
        .then(data => {
            if(data.success && data.data) {
                let obj = data.data;
                document.getElementById('post_frequency_select').value = obj.freq || 'daily';
                if(obj.freq === 'custom') {
                    document.getElementById('custom_days_field').style.display = '';
                    document.getElementById('custom_days_input').value = obj.days || '';
                } else {
                    document.getElementById('custom_days_field').style.display = 'none';
                }
            }
        });
    }

    function contentpilot_load_saved_keywords_table() {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>' + '?action=contentpilot_get_saved_keywords_table')
        .then(response => response.json())
        .then(data => {
            let tableDiv = document.getElementById('keyword_saved_table');
            if (data.success) {
                tableDiv.innerHTML = data.data;
                contentpilot_bind_edit_actions();
            } else {
                tableDiv.innerHTML = "<strong style='color:red;'>Error: " + data.data + "</strong>";
            }
        });
    }

    // Bind edit/delete actions after table render
    function contentpilot_bind_edit_actions() {
        // Edit keyword
        document.querySelectorAll(".keyword-edit-btn").forEach(btn => {
            btn.onclick = function() {
                let id = this.getAttribute("data-id");
                let td = document.getElementById("keyword_value_"+id);
                let origVal = td.innerText;
                td.innerHTML = `<input type="text" id="keyword_edit_input_${id}" value="${origVal}" style="width:80%;" /> <button onclick="contentpilot_save_edited_keyword('${id}')">Save</button> <button onclick="contentpilot_cancel_edit_keyword('${id}', '${origVal}')">Cancel</button>`;
            };
        });
        // Delete keyword
        document.querySelectorAll(".keyword-delete-btn").forEach(btn => {
            btn.onclick = function() {
                let id = this.getAttribute("data-id");
                if(confirm("Delete this keyword?")) {
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=contentpilot_delete_keyword&id='+encodeURIComponent(id)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if(data.success) {
                            contentpilot_load_saved_keywords_table();
                        } else {
                            alert('Error deleting keyword: ' + data.data);
                        }
                    });
                }
            };
        });
    }

    function contentpilot_save_edited_keyword(id) {
        let val = document.getElementById("keyword_edit_input_"+id).value;
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=contentpilot_edit_keyword&id='+encodeURIComponent(id)+'&keyword='+encodeURIComponent(val)
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                contentpilot_load_saved_keywords_table();
            } else {
                alert('Error editing keyword: ' + data.data);
            }
        });
    }

    function contentpilot_cancel_edit_keyword(id, origVal) {
        document.getElementById("keyword_value_"+id).innerText = origVal;
    }
    </script>
    <?php
}

// SCHEDULED POSTS PAGE (unchanged, same as your current)
function contentpilot_ai_render_scheduled_page() {
    ?>
    <div class="wrap">
        <h1>Scheduled AI Posts</h1>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Scheduled For</th>
                    <th>Preview</th>
                    <th>Edit</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $future_posts = get_posts([
                    'post_type' => 'post',
                    'post_status' => 'future',
                    'meta_key' => 'ai_generated',
                    'meta_value' => 'yes',
                    'orderby' => 'post_date',
                    'order' => 'ASC',
                    'numberposts' => 20
                ]);
                if($future_posts) {
                    foreach($future_posts as $post) {
                        echo '<tr>';
                        echo '<td>' . esc_html($post->post_title) . '</td>';
                        echo '<td>' . esc_html(date('Y-m-d H:i', strtotime($post->post_date))) . '</td>';
                        echo '<td><a href="' . get_permalink($post->ID) . '" target="_blank">View</a></td>';
                        echo '<td><a href="' . get_edit_post_link($post->ID) . '" target="_blank">Edit</a></td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="4">No scheduled posts found.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

// --- SAVE KEYWORD + ERROR LOG ON CREATE
add_action('wp_ajax_contentpilot_save_keywords', 'contentpilot_save_keywords');
function contentpilot_save_keywords() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error("Unauthorized");
    }
    if ( empty($_POST['keywords']) ) {
        wp_send_json_error("No keywords provided");
    }

    global $wpdb;
    $table = $wpdb->prefix . 'contentpilot_keywords';
    $user_id = get_current_user_id();
    $raw = is_array($_POST['keywords']) ? $_POST['keywords'] : stripslashes($_POST['keywords']);
    $keywords = json_decode($raw, true);
    if(!is_array($keywords)) {
        // attempt comma separated
        $keywords = array_map('trim', explode(',', $raw));
    }
    if(!is_array($keywords)) $keywords = [];
    foreach($keywords as $kw) {
        if (trim($kw) === '') continue;
        $kw_s = sanitize_text_field($kw);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE keyword = %s AND user_id = %d", $kw_s, $user_id));
        if(!$exists) {
            $wpdb->insert($table, [
                'keyword' => $kw_s,
                'user_id' => $user_id,
                'post_id' => null,
                'error_log' => ''
            ]);
        }
    }
    wp_send_json_success("Saved!");
}

// --- TABLE: show error log, blog link
add_action('wp_ajax_contentpilot_get_saved_keywords_table', 'contentpilot_get_saved_keywords_table');
function contentpilot_get_saved_keywords_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'contentpilot_keywords';
    $user_id = get_current_user_id();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY added DESC", $user_id));
    $html = "<table class='widefat fixed striped'>
    <thead><tr>
        <th>Keyword</th>
        <th>Added</th>
        <th>Post Link</th>
        <th>Error Log</th>
        <th>Actions</th>
    </tr></thead><tbody>";
    foreach($rows as $row) {
        $postUrl = ($row->post_id && get_permalink($row->post_id)) ? get_permalink($row->post_id) : '';
        $html .= "<tr>";
        $html .= "<td id='keyword_value_{$row->id}'>" . esc_html($row->keyword) . "</td>";
        $html .= "<td>" . esc_html(date('Y-m-d H:i', strtotime($row->added))) . "</td>";
        $html .= "<td>" . ($postUrl ? "<a href='" . esc_url($postUrl) . "' target='_blank'>View Blog</a>" : '<em>Pending</em>') . "</td>";
        $html .= "<td style='color:red;'>" . ($row->error_log ? esc_html($row->error_log) : "") . "</td>";
        $html .= "<td><button class='button button-small keyword-edit-btn' data-id='{$row->id}'>Edit</button> 
            <button class='button button-small keyword-delete-btn' data-id='{$row->id}'>Delete</button></td>";
        $html .= "</tr>";
    }
    $html .= "</tbody></table>";
    wp_send_json_success($html);
}

// --- AJAX: keyword edit resets error log
add_action('wp_ajax_contentpilot_edit_keyword', 'contentpilot_edit_keyword');
function contentpilot_edit_keyword() {
    global $wpdb;
    $table = $wpdb->prefix . 'contentpilot_keywords';
    $user_id = get_current_user_id();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
    if (!$id || $keyword === '') {
        wp_send_json_error("Invalid input");
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND user_id=%d", $id, $user_id));
    if($row) {
        $wpdb->update($table, ['keyword'=>$keyword, 'error_log'=>''], ['id'=>$id]);
        wp_send_json_success("Keyword updated");
    } else {
        wp_send_json_error("Keyword not found");
    }
}
add_action('wp_ajax_contentpilot_delete_keyword', 'contentpilot_delete_keyword');
function contentpilot_delete_keyword() {
    global $wpdb;
    $table = $wpdb->prefix . 'contentpilot_keywords';
    $user_id = get_current_user_id();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if (!$id) {
        wp_send_json_error("Invalid id");
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND user_id=%d", $id, $user_id));
    if($row) {
        $wpdb->delete($table, ['id'=>$id]);
        wp_send_json_success("Keyword deleted");
    } else {
        wp_send_json_error("Keyword not found");
    }
}

// AJAX for generator, publish, frequency, etc (no changes from v1.0 part above)
// ... include your generate_blog, publish_post, planner, frequency save/get handlers ...

// --- FINAL CRON: multi-blog per run, time window, featured images, error logs ---
if (!wp_next_scheduled('contentpilot_cron_generate_blogs')) {
    wp_schedule_event(time(), 'hourly', 'contentpilot_cron_generate_blogs');
}
add_action('contentpilot_cron_generate_blogs', 'contentpilot_generate_blogs_automatically');

function contentpilot_generate_blogs_automatically() {
    global $wpdb;
    $table = $wpdb->prefix . 'contentpilot_keywords';
    $users = get_users(['fields' => 'ID']);
    if (empty($users)) return;
    $max_per_run = 3; // how many blogs per user per run
    $target_hour = 9; // only post if server hour >= 9

    foreach ($users as $user_id) {
        // User frequency checking
        $freq_val = get_user_meta($user_id, 'contentpilot_keyword_frequency', true);
        $freq = $freq_val ? json_decode($freq_val, true) : ['freq' => 'daily', 'days' => 1];
        $key = 'contentpilot_last_auto_blog_time';
        $now = current_time('timestamp');
        $current_hour = intval(date('G', $now));
        if($current_hour < $target_hour) continue;

        $last_run = get_user_meta($user_id, $key, true);
        $run_again = false;
        if(!$last_run) $run_again = true;
        else {
            $elapsed = $now - intval($last_run);
            switch($freq['freq']) {
                case 'daily': $run_again = $elapsed > DAY_IN_SECONDS; break;
                case 'weekly': $run_again = $elapsed > WEEK_IN_SECONDS; break;
                case 'monthly': $run_again = $elapsed > 30 * DAY_IN_SECONDS; break;
                case 'custom':
                    $run_again = $elapsed > ((isset($freq['days']) ? intval($freq['days']) : 1) * DAY_IN_SECONDS);
                    break;
                default: $run_again = true;
            }
        }
        if(!$run_again) continue;

        // Get all unused keywords for this user
        $keywords = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND (post_id IS NULL OR post_id = '')", $user_id));
        $used = 0;
        foreach($keywords as $keyword_row) {
            if($used >= $max_per_run) break;

            $prompt = "Write a comprehensive, high-quality blog post targeting the keyword: '{$keyword_row->keyword}'. Use the keyword in title, headings, intro, and throughout. Follow best modern SEO practices: meta, headings, subtopics. Output JSON: 'title', 'content' (HTML), 'meta_description', optional 'featured_image_url'.";

            $response = wp_remote_post('https://contentpilot-backend-12am.onrender.com/ai/generate-blog', [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode(['prompt' => $prompt]),
                'timeout' => 600,
            ]);
            if (is_wp_error($response)) {
                $wpdb->update($table, ['error_log'=>$response->get_error_message()], ['id'=>$keyword_row->id]);
                continue;
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($body['title'], $body['content'])) {
                $wpdb->update($table, ['error_log'=>"AI did not return blog content"], ['id'=>$keyword_row->id]);
                continue;
            }
            // Handle featured image
            $attach_id = null;
            if (!empty($body['featured_image_url'])) {
                // include required files for media handling
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');

                $image_url = $body['featured_image_url'];
                $image_name = 'ai-featured-' . time() . '.png';
                $upload_dir = wp_upload_dir();
                $file = trailingslashit($upload_dir['path']) . $image_name;
                $image_response = wp_remote_get($image_url, array('timeout' => 30));
                if (!is_wp_error($image_response)) {
                    $image_data = wp_remote_retrieve_body($image_response);
                    if ($image_data && file_put_contents($file, $image_data)) {
                        $wp_filetype = wp_check_filetype($image_name, null);
                        $attachment = [
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title'     => 'AI Generated Featured Image',
                            'post_content'   => '',
                            'post_status'    => 'inherit'
                        ];
                        $attach_id = wp_insert_attachment($attachment, $file, 0);
                        if (!is_wp_error($attach_id) && $attach_id) {
                            $attach_data = wp_generate_attachment_metadata($attach_id, $file);
                            wp_update_attachment_metadata($attach_id, $attach_data);
                        } else {
                            $attach_id = null;
                        }
                    }
                }
            }
            // Publish blog
            $post_id = wp_insert_post([
                'post_title'    => wp_strip_all_tags($body['title']),
                'post_content'  => $body['content'],
                'post_status'   => 'publish',
                'post_author'   => $user_id,
            ]);
            if (is_wp_error($post_id) || !$post_id) {
                $err = is_wp_error($post_id) ? $post_id->get_error_message() : "WP failed publishing post";
                $wpdb->update($table, ['error_log'=>$err], ['id'=>$keyword_row->id]);
                continue;
            }
            if (!empty($body['meta_description'])) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($body['meta_description']));
            }
            if ($attach_id) set_post_thumbnail($post_id, $attach_id);

            $wpdb->update($table, ['post_id' => $post_id, 'error_log'=>''], ['id' => $keyword_row->id]);
            $used++;
        }
        update_user_meta($user_id, $key, $now);
    }
}

/* ...Keep your AJAX for contentpilot_generate_blog, contentpilot_publish_post, planner, frequency save/get... */

add_action('wp_ajax_contentpilot_generate_blog', 'contentpilot_generate_blog');
function contentpilot_generate_blog() {
    $prompt = sanitize_text_field($_POST['prompt']);
    $response = wp_remote_post('https://contentpilot-backend-12am.onrender.com/ai/generate-blog', [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode(['prompt' => $prompt]),
        'timeout' => 600,
    ]);
    error_log(print_r($response, true));
    if (is_wp_error($response)) {
        wp_send_json_error('Backend error: ' . $response->get_error_message());
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (is_array($body) && isset($body['title']) && isset($body['content'])) {
        wp_send_json_success($body);
    } else {
        wp_send_json_error('Invalid AI response');
    }
}

// Publish (and optionally schedule) post
add_action('wp_ajax_contentpilot_publish_post', 'contentpilot_publish_post');
function contentpilot_publish_post() {
    $ai_data = json_decode(stripslashes($_POST['content']), true);
    $publish_date = isset($_POST['publish_date']) ? sanitize_text_field($_POST['publish_date']) : '';
    if (empty($ai_data['title']) || empty($ai_data['content'])) {
        wp_send_json_error('Missing title or content in AI response.');
        return;
    }
    $post_status = 'publish';
    $post_date = '';
    if (!empty($publish_date)) {
        $post_status = 'future';
        $publish_date_wp = str_replace('T', ' ', $publish_date) . ':00';
        $post_date = $publish_date_wp;
    }
    $post_args = [
        'post_title'    => $ai_data['title'],
        'post_content'  => $ai_data['content'],
        'post_status'   => $post_status,
        'post_author'   => get_current_user_id(),
    ];
    if ($post_status === 'future' && !empty($post_date)) {
        $post_args['post_date'] = $post_date;
        $post_args['post_date_gmt'] = get_gmt_from_date($post_date);
    }
    $post_id = wp_insert_post($post_args);
    error_log('wp_insert_post returned: ' . print_r($post_id, true));
    if ($post_id && !is_wp_error($post_id)) {
        // Mark as AI generated
        update_post_meta($post_id, 'ai_generated', 'yes');
        $permalink = get_permalink($post_id);
        if ($permalink) {
            wp_send_json_success(['post_url' => $permalink]);
        } else {
            wp_send_json_error('Error: Permalink not found for post.');
        }
    } elseif (is_wp_error($post_id)) {
        error_log('wp_insert_post error: ' . $post_id->get_error_message());
        wp_send_json_error('Could not publish post: ' . $post_id->get_error_message());
    } else {
        wp_send_json_error('Could not publish post');
    }
}
// AI Keyword Planner form handler
add_action('wp_ajax_contentpilot_ai_keyword_planner', 'contentpilot_ai_keyword_planner');
function contentpilot_ai_keyword_planner() {
    $form_data = [];
    if (!empty($_POST['data'])) {
        $form_data = json_decode(stripslashes($_POST['data']), true);
    }
    $prompt = "Act as an SEO expert. Based on the following business info, suggest the best 20 keywords to target (include monthly search volume and related data if possible):\n";
    foreach ($form_data as $label => $answer) {
        $prompt .= ucfirst(str_replace('_', ' ', $label)) . ': ' . $answer . "\n";
    }
    $response = wp_remote_post('https://contentpilot-backend-12am.onrender.com/ai/keyword-plan', [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode(['prompt' => $prompt]),
        'timeout' => 120,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('Backend error: ' . $response->get_error_message());
    }
    $body = wp_remote_retrieve_body($response);
    wp_send_json_success($body);
}

// Save posting frequency in user meta
add_action('wp_ajax_contentpilot_save_post_frequency', 'contentpilot_save_post_frequency');
function contentpilot_save_post_frequency() {
    $user_id = get_current_user_id();
    $freq = sanitize_text_field($_POST['freq']);
    $days = (isset($_POST['days']) ? intval($_POST['days']) : '');
    if($freq === 'custom' && (!$days || $days < 1)) {
        wp_send_json_error("Custom days must be a positive integer.");
    }
    update_user_meta($user_id, 'contentpilot_keyword_frequency', json_encode([
        'freq' => $freq,
        'days' => $days
    ]));
    wp_send_json_success("Saved!");
}
add_action('wp_ajax_contentpilot_get_post_frequency', 'contentpilot_get_post_frequency');
function contentpilot_get_post_frequency() {
    $user_id = get_current_user_id();
    $val = get_user_meta($user_id, 'contentpilot_keyword_frequency', true);
    $obj = $val ? json_decode($val, true) : ['freq'=>'daily','days'=>''];
    wp_send_json_success($obj);
}
?>
