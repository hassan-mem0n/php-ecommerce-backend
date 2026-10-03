<?php
declare(strict_types=1);

/* ================= CATEGORIES ================= */

function categoryList(): void {
    $rows = q('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
    respond(200, ['categories' => array_map('fmtCategory', $rows)]);
}

function categoryCreate(): void {
    $b = body();
    $name = s($b, 'name');
    if ($name === '') respond(400, ['message' => 'Category name is required']);
    try {
        q('INSERT INTO categories (name, description, image) VALUES (?, ?, ?)',
          [$name, s($b, 'description'), s($b, 'image')]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') respond(400, ['message' => 'Category already exists']);
        throw $e;
    }
    $row = findById('categories', db()->lastInsertId());
    respond(201, ['message' => 'Category created successfully', 'category' => fmtCategory($row)]);
}

function categoryUpdate(array $p): void {
    $cat = findById('categories', $p['id']);
    if (!$cat) respond(404, ['message' => 'Category not found']);
    $b = body();
    $set = [];
    foreach (['name', 'description', 'image'] as $f) if (array_key_exists($f, $b)) $set[$f] = s($b, $f);
    if (array_key_exists('isActive', $b)) $set['is_active'] = $b['isActive'] ? 1 : 0;
    if (isset($set['name']) && $set['name'] === '') respond(400, ['message' => 'Category name is required']);
    try {
        updateRow('categories', (int)$cat['id'], $set);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') respond(400, ['message' => 'Category already exists']);
        throw $e;
    }
    respond(200, ['message' => 'Category updated successfully', 'category' => fmtCategory(findById('categories', $cat['id']))]);
}

function categoryDelete(array $p): void {
    $cat = findById('categories', $p['id']);
    if (!$cat) respond(404, ['message' => 'Category not found']);
    if (q('SELECT COUNT(*) FROM services WHERE category_id = ?', [$cat['id']])->fetchColumn() > 0) {
        respond(400, ['message' => 'Cannot delete a category that still has services']);
    }
    q('DELETE FROM categories WHERE id = ?', [$cat['id']]);
    respond(200, ['message' => 'Category deleted successfully']);
}

/* ================= SERVICES ================= */

function serviceWithCategory(array $r): array {
    return fmtService($r, ['_id' => (string)$r['category_id'], 'name' => $r['category_name']]);
}

function serviceList(): void {
    $rows = q('SELECT s.*, c.name AS category_name FROM services s JOIN categories c ON c.id = s.category_id
               ORDER BY s.created_at DESC, s.id DESC')->fetchAll();
    respond(200, ['services' => array_map('serviceWithCategory', $rows)]);
}

function serviceGet(array $p): void {
    $row = ctype_digit($p['id']) ? q('SELECT s.*, c.name AS category_name FROM services s
            JOIN categories c ON c.id = s.category_id WHERE s.id = ?', [(int)$p['id']])->fetch() : null;
    if (!$row) respond(404, ['message' => 'Service not found']);
    respond(200, ['service' => serviceWithCategory($row)]);
}

/** Validate + map request body to DB columns. $partial = true for updates. */
function serviceFields(array $b, bool $partial): array {
    $map = ['name' => 'name', 'category' => 'category_id', 'price' => 'price', 'priceType' => 'price_type',
            'availableFrom' => 'available_from', 'availableTo' => 'available_to', 'details' => 'details', 'image' => 'image'];
    $set = [];
    foreach ($map as $key => $col) {
        if (!array_key_exists($key, $b)) continue;
        $set[$col] = is_scalar($b[$key]) ? trim((string)$b[$key]) : '';
    }
    if ($partial && array_key_exists('isActive', $b)) $set['is_active'] = $b['isActive'] ? 1 : 0;

    if (isset($set['price']) && (!is_numeric($set['price']) || (float)$set['price'] < 0)) respond(400, ['message' => 'Invalid price']);
    if (isset($set['price_type']) && !in_array($set['price_type'], ['hour', 'day'], true)) respond(400, ['message' => 'priceType must be "hour" or "day"']);
    if (isset($set['category_id']) && !findById('categories', $set['category_id'])) respond(400, ['message' => 'Category not found']);
    foreach (['name', 'available_from', 'available_to', 'details'] as $col) {
        if (isset($set[$col]) && $set[$col] === '') respond(400, ['message' => 'All required fields are required']);
    }
    return $set;
}

function serviceCreate(): void {
    $b = body();
    foreach (['name', 'category', 'priceType', 'availableFrom', 'availableTo', 'details'] as $f) {
        if (s($b, $f) === '') respond(400, ['message' => 'All required fields are required']);
    }
    if (!isset($b['price']) || $b['price'] === '') respond(400, ['message' => 'All required fields are required']);

    $set = serviceFields($b, false);
    $set += ['image' => ''];
    $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($set)));
    $marks = implode(',', array_fill(0, count($set), '?'));
    q("INSERT INTO services ($cols) VALUES ($marks)", array_values($set));

    $row = findById('services', db()->lastInsertId());
    respond(201, ['message' => 'Service created successfully', 'service' => fmtService($row)]);
}

function serviceUpdate(array $p): void {
    $svc = findById('services', $p['id']);
    if (!$svc) respond(404, ['message' => 'Service not found']);
    updateRow('services', (int)$svc['id'], serviceFields(body(), true));
    respond(200, ['message' => 'Service updated successfully', 'service' => fmtService(findById('services', $svc['id']))]);
}

function serviceDelete(array $p): void {
    $svc = findById('services', $p['id']);
    if (!$svc) respond(404, ['message' => 'Service not found']);
    q('DELETE FROM services WHERE id = ?', [$svc['id']]);
    respond(200, ['message' => 'Service deleted successfully']);
}
