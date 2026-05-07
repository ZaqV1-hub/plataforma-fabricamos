<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php import_fabricamos_associados.php <wp-load.php> <json-file> [--match-dictionary] [--credentials-output=/path/to/file.csv] [--reset-existing-passwords] [--user-role=author]\n");
    exit(1);
}

$wpLoadPath = $argv[1];
$jsonPath = $argv[2];
$options = parse_cli_options(array_slice($argv, 3));

if (! file_exists($wpLoadPath)) {
    fwrite(STDERR, "wp-load.php not found: {$wpLoadPath}\n");
    exit(1);
}

if (! file_exists($jsonPath)) {
    fwrite(STDERR, "JSON file not found: {$jsonPath}\n");
    exit(1);
}

require $wpLoadPath;

if ($options['match_dictionary'] && ! class_exists('Fabricamos_Native')) {
    fwrite(STDERR, "Fabricamos_Native plugin is not loaded.\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Unable to read JSON file.\n");
    exit(1);
}

$companies = json_decode($raw, true);
if (! is_array($companies)) {
    fwrite(STDERR, "Invalid JSON payload.\n");
    exit(1);
}

$fabricamos = $options['match_dictionary'] ? Fabricamos_Native::instance() : null;
$substanceIndex = $options['match_dictionary'] ? build_substance_index() : array();
$credentialsWriter = open_credentials_output($options['credentials_output']);

$created = 0;
$updated = 0;
$usersCreated = 0;
$usersUpdated = 0;
$passwordsGenerated = 0;
$matchedSubstances = 0;
$unmatchedSubstances = 0;
$rowsImported = 0;

foreach ($companies as $item) {
    $company = normalize_text((string) ($item['company'] ?? ''));
    if ($company === '') {
        continue;
    }

    $processes = normalize_text_list((array) ($item['processes'] ?? array()));
    $origins = normalize_text_list((array) ($item['origins'] ?? array()));
    $catalogItems = normalize_catalog_items((array) ($item['catalog_items'] ?? array()));
    $compiledSubstances = normalize_text_list((array) ($item['substances'] ?? array()));

    if (empty($compiledSubstances) && ! empty($catalogItems)) {
        $compiledSubstances = derive_compiled_substances_from_catalog_items($catalogItems);
    }

    $associateStatus = normalize_text((string) ($item['associate'] ?? 'Associado'));
    $responsibleName = normalize_text((string) ($item['responsible_name'] ?? ''));
    $responsiblePhone = normalize_text((string) ($item['responsible_phone'] ?? ''));
    $responsibleEmail = normalize_text((string) ($item['responsible_email'] ?? ''));
    $sourceWorkbook = normalize_text((string) ($item['source_workbook'] ?? ''));
    $sourceSheet = normalize_text((string) ($item['source_sheet'] ?? ''));
    $sourceUpdatedLabel = normalize_text((string) ($item['source_updated_label'] ?? ''));

    $editorAccount = ensure_editor_account(
        $responsibleName,
        $responsibleEmail,
        $responsiblePhone,
        $options['user_role'],
        $options['reset_existing_passwords']
    );

    if ($editorAccount['status'] === 'created') {
        $usersCreated++;
    } elseif ($editorAccount['status'] === 'updated' || $editorAccount['status'] === 'password_reset') {
        $usersUpdated++;
    }

    if ($editorAccount['generated_password'] !== '') {
        $passwordsGenerated++;
        write_credentials_row($credentialsWriter, array(
            'company' => $company,
            'responsible_name' => $responsibleName,
            'email' => $responsibleEmail,
            'username' => $editorAccount['username'],
            'password' => $editorAccount['generated_password'],
            'status' => $editorAccount['status'],
            'user_id' => (string) $editorAccount['user_id'],
        ));
    }

    $manufacturerId = find_manufacturer_by_title($company);
    $isNew = false;

    if ($manufacturerId === 0) {
        $manufacturerId = wp_insert_post(array(
            'post_type' => 'fabricante',
            'post_status' => 'publish',
            'post_title' => $company,
            'post_content' => '',
            'post_author' => $editorAccount['user_id'] > 0 ? $editorAccount['user_id'] : 0,
        ), true);

        if (is_wp_error($manufacturerId)) {
            fwrite(STDERR, "Failed to create manufacturer {$company}: {$manufacturerId->get_error_message()}\n");
            continue;
        }

        $manufacturerId = (int) $manufacturerId;
        $created++;
        $isNew = true;
    } else {
        $currentAuthorId = (int) get_post_field('post_author', $manufacturerId);
        wp_update_post(array(
            'ID' => $manufacturerId,
            'post_title' => $company,
            'post_status' => 'publish',
            'post_author' => $editorAccount['user_id'] > 0 ? $editorAccount['user_id'] : $currentAuthorId,
        ));
        $updated++;
    }

    update_post_meta($manufacturerId, 'fab_associate_status', $associateStatus);
    update_post_meta($manufacturerId, 'fab_processo', implode(' / ', $processes));
    update_post_meta($manufacturerId, 'fab_origem', implode(' / ', $origins));
    update_post_meta($manufacturerId, 'fab_compiled_substances', array_values(array_unique($compiledSubstances)));
    update_post_meta($manufacturerId, 'fab_catalog_items', $catalogItems);
    sync_post_meta_text($manufacturerId, 'fab_responsavel_nome', $responsibleName);
    sync_post_meta_text($manufacturerId, 'fab_responsavel_telefone', $responsiblePhone);
    sync_post_meta_text($manufacturerId, 'fab_responsavel_email', $responsibleEmail);
    sync_post_meta_text($manufacturerId, 'fab_source_workbook', $sourceWorkbook);
    sync_post_meta_text($manufacturerId, 'fab_source_sheet', $sourceSheet);
    sync_post_meta_text($manufacturerId, 'fab_source_updated_label', $sourceUpdatedLabel);

    if ($editorAccount['user_id'] > 0) {
        update_post_meta($manufacturerId, 'fab_editor_user_id', $editorAccount['user_id']);
        sync_post_meta_text($manufacturerId, 'fab_editor_username', $editorAccount['username']);
        sync_post_meta_text($manufacturerId, 'fab_editor_email', $responsibleEmail);
    } else {
        delete_post_meta($manufacturerId, 'fab_editor_user_id');
        delete_post_meta($manufacturerId, 'fab_editor_username');
        delete_post_meta($manufacturerId, 'fab_editor_email');
    }

    $matchedIds = array();
    if ($options['match_dictionary']) {
        foreach ($compiledSubstances as $substanceName) {
            $matchedId = match_substance_post_id($substanceName, $substanceIndex, $fabricamos);
            if ($matchedId > 0) {
                $matchedIds[] = $matchedId;
                $matchedSubstances++;
            } else {
                $unmatchedSubstances++;
            }
        }
    }

    $matchedIds = array_values(array_unique(array_map('absint', $matchedIds)));
    if (function_exists('update_field')) {
        update_field('field_fab_substances', $matchedIds, $manufacturerId);
    } else {
        delete_post_meta($manufacturerId, 'fab_substances');
        update_post_meta($manufacturerId, 'fab_substances', $matchedIds);
    }

    if ($isNew) {
        echo "CREATED|{$manufacturerId}|{$company}|substances=" . count($compiledSubstances) . "|matched=" . count($matchedIds) . "|user=" . $editorAccount['status'] . PHP_EOL;
    } else {
        echo "UPDATED|{$manufacturerId}|{$company}|substances=" . count($compiledSubstances) . "|matched=" . count($matchedIds) . "|user=" . $editorAccount['status'] . PHP_EOL;
    }

    $rowsImported++;
}

close_credentials_output($credentialsWriter);

echo "SUMMARY|rows={$rowsImported}|created={$created}|updated={$updated}|users_created={$usersCreated}|users_updated={$usersUpdated}|passwords_generated={$passwordsGenerated}|matched_substances={$matchedSubstances}|unmatched_substances={$unmatchedSubstances}" . PHP_EOL;

function parse_cli_options(array $args): array
{
    $options = array(
        'match_dictionary' => false,
        'credentials_output' => null,
        'reset_existing_passwords' => false,
        'user_role' => 'author',
    );

    foreach ($args as $arg) {
        if ($arg === '--match-dictionary') {
            $options['match_dictionary'] = true;
            continue;
        }

        if ($arg === '--reset-existing-passwords') {
            $options['reset_existing_passwords'] = true;
            continue;
        }

        if (str_starts_with($arg, '--credentials-output=')) {
            $options['credentials_output'] = substr($arg, strlen('--credentials-output='));
            continue;
        }

        if (str_starts_with($arg, '--user-role=')) {
            $options['user_role'] = substr($arg, strlen('--user-role='));
            continue;
        }
    }

    return $options;
}

function find_manufacturer_by_title(string $title): int
{
    $posts = get_posts(array(
        'post_type' => 'fabricante',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => 1,
        'title' => $title,
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => false,
    ));

    if (! empty($posts)) {
        return (int) $posts[0]->ID;
    }

    global $wpdb;
    $postId = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private') AND post_title = %s ORDER BY ID ASC LIMIT 1",
        'fabricante',
        $title
    ));

    return $postId ? (int) $postId : 0;
}

function ensure_editor_account(
    string $responsibleName,
    string $responsibleEmail,
    string $responsiblePhone,
    string $preferredRole,
    bool $resetExistingPasswords
): array {
    $emptyResult = array(
        'user_id' => 0,
        'username' => '',
        'generated_password' => '',
        'status' => 'none',
    );

    if ($responsibleEmail === '') {
        return $emptyResult;
    }

    $email = sanitize_email($responsibleEmail);
    if (! is_email($email)) {
        fwrite(STDERR, "Invalid email for responsible contact: {$responsibleEmail}\n");
        return $emptyResult;
    }

    $role = resolve_user_role($preferredRole);
    $user = get_user_by('email', $email);

    if ($user instanceof WP_User) {
        $payload = array(
            'ID' => (int) $user->ID,
            'display_name' => $responsibleName !== '' ? $responsibleName : $user->display_name,
        );

        if ($role !== '' && empty($user->roles)) {
            $payload['role'] = $role;
        }

        wp_update_user($payload);
        if ($responsibleName !== '') {
            update_user_meta($user->ID, 'first_name', $responsibleName);
        }
        if ($responsiblePhone !== '') {
            update_user_meta($user->ID, 'fab_responsavel_telefone', $responsiblePhone);
        }

        $status = 'existing';
        $password = '';
        if ($resetExistingPasswords) {
            $password = wp_generate_password(18, true, true);
            wp_set_password($password, $user->ID);
            $status = 'password_reset';
        }

        return array(
            'user_id' => (int) $user->ID,
            'username' => (string) $user->user_login,
            'generated_password' => $password,
            'status' => $status,
        );
    }

    $username = generate_unique_username($email, $responsibleName);
    $password = wp_generate_password(18, true, true);

    $userId = wp_create_user($username, $password, $email);
    if (is_wp_error($userId)) {
        fwrite(STDERR, "Failed to create user {$email}: {$userId->get_error_message()}\n");
        return $emptyResult;
    }

    $userId = (int) $userId;
    if ($role !== '') {
        $wpUser = new WP_User($userId);
        $wpUser->set_role($role);
    }

    wp_update_user(array(
        'ID' => $userId,
        'display_name' => $responsibleName !== '' ? $responsibleName : $username,
    ));

    if ($responsibleName !== '') {
        update_user_meta($userId, 'first_name', $responsibleName);
    }
    if ($responsiblePhone !== '') {
        update_user_meta($userId, 'fab_responsavel_telefone', $responsiblePhone);
    }

    return array(
        'user_id' => $userId,
        'username' => $username,
        'generated_password' => $password,
        'status' => 'created',
    );
}

function build_substance_index(): array
{
    $posts = get_posts(array(
        'post_type' => 'post',
        'post_status' => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ));

    $index = array();
    foreach ($posts as $post) {
        $normalized = normalize_lookup_value($post->post_title);
        if ($normalized === '') {
            continue;
        }

        if (! isset($index[$normalized])) {
            $index[$normalized] = (int) $post->ID;
        }
    }

    return $index;
}

function match_substance_post_id(string $name, array $index, Fabricamos_Native $fabricamos): int
{
    $normalized = normalize_lookup_value($name);
    if ($normalized === '') {
        return 0;
    }

    if (isset($index[$normalized])) {
        return (int) $index[$normalized];
    }

    $results = $fabricamos->search_substances($name, 10);
    if (empty($results)) {
        return 0;
    }

    foreach ($results as $post) {
        if (normalize_lookup_value($post->post_title) === $normalized) {
            return (int) $post->ID;
        }
    }

    if (count($results) === 1) {
        return (int) $results[0]->ID;
    }

    foreach ($results as $post) {
        $candidate = normalize_lookup_value($post->post_title);
        if ($candidate !== '' && (str_contains($candidate, $normalized) || str_contains($normalized, $candidate))) {
            return (int) $post->ID;
        }
    }

    return 0;
}

function normalize_lookup_value(string $value): string
{
    $value = wp_strip_all_tags($value);
    $value = remove_accents($value);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', (string) $value);
    return trim((string) $value);
}

function normalize_text(string $value): string
{
    $value = str_replace(array("\r", "\n"), ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim((string) $value);
}

function normalize_text_list(array $values): array
{
    $normalized = array();
    foreach ($values as $value) {
        $text = normalize_text((string) $value);
        if ($text === '') {
            continue;
        }
        if (! in_array($text, $normalized, true)) {
            $normalized[] = $text;
        }
    }

    return $normalized;
}

function normalize_catalog_items(array $items): array
{
    $normalized = array();

    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }

        $catalogItem = array(
            'insumo' => normalize_text((string) ($item['insumo'] ?? '')),
            'dcb' => normalize_text((string) ($item['dcb'] ?? '')),
            'inn' => normalize_text((string) ($item['inn'] ?? '')),
            'cas' => normalize_text((string) ($item['cas'] ?? '')),
            'ncm' => normalize_text((string) ($item['ncm'] ?? '')),
            'cbpf' => normalize_text((string) ($item['cbpf'] ?? '')),
            'validade' => normalize_text((string) ($item['validade'] ?? '')),
            'display_name' => normalize_text((string) ($item['display_name'] ?? '')),
        );

        if (implode('', $catalogItem) === '') {
            continue;
        }

        $normalized[] = $catalogItem;
    }

    return $normalized;
}

function derive_compiled_substances_from_catalog_items(array $catalogItems): array
{
    $substances = array();

    foreach ($catalogItems as $item) {
        $displayName = normalize_text((string) ($item['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = normalize_text((string) ($item['inn'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = normalize_text((string) ($item['insumo'] ?? ''));
        }
        if ($displayName === '') {
            continue;
        }
        if (! in_array($displayName, $substances, true)) {
            $substances[] = $displayName;
        }
    }

    return $substances;
}

function sync_post_meta_text(int $postId, string $metaKey, string $value): void
{
    if ($value === '') {
        delete_post_meta($postId, $metaKey);
        return;
    }

    update_post_meta($postId, $metaKey, $value);
}

function resolve_user_role(string $preferredRole): string
{
    $preferredRole = normalize_text($preferredRole);
    $roles = wp_roles()->roles;

    if ($preferredRole !== '' && isset($roles[$preferredRole])) {
        return $preferredRole;
    }

    foreach (array('author', 'subscriber') as $fallbackRole) {
        if (isset($roles[$fallbackRole])) {
            return $fallbackRole;
        }
    }

    return '';
}

function generate_unique_username(string $email, string $responsibleName): string
{
    $candidates = array();
    $localPart = strstr($email, '@', true);
    if ($localPart !== false) {
        $candidates[] = sanitize_user($localPart, true);
    }
    $candidates[] = sanitize_user($responsibleName, true);
    $candidates[] = sanitize_user(str_replace('@', '_', $email), true);

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if (! username_exists($candidate)) {
            return $candidate;
        }

        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $trial = $candidate . $suffix;
            if (! username_exists($trial)) {
                return $trial;
            }
        }
    }

    do {
        $fallback = 'fabricamos_' . wp_rand(10000, 99999);
    } while (username_exists($fallback));

    return $fallback;
}

function open_credentials_output(?string $path): ?array
{
    if ($path === null || $path === '') {
        return null;
    }

    $directory = dirname($path);
    if (! is_dir($directory)) {
        wp_mkdir_p($directory);
    }

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open credentials output: {$path}\n");
        return null;
    }

    fputcsv($handle, array('company', 'responsible_name', 'email', 'username', 'password', 'status', 'user_id'));

    return array(
        'path' => $path,
        'handle' => $handle,
    );
}

function write_credentials_row(?array $writer, array $row): void
{
    if ($writer === null) {
        return;
    }

    fputcsv($writer['handle'], array(
        $row['company'] ?? '',
        $row['responsible_name'] ?? '',
        $row['email'] ?? '',
        $row['username'] ?? '',
        $row['password'] ?? '',
        $row['status'] ?? '',
        $row['user_id'] ?? '',
    ));
}

function close_credentials_output(?array $writer): void
{
    if ($writer === null) {
        return;
    }

    fclose($writer['handle']);
    echo "CREDENTIALS_FILE|{$writer['path']}" . PHP_EOL;
}
