<?php
require_once __DIR__ . '/functions/sell-functions.php';

$error = '';
$stories = [];
try {
    $stories = hmd_get_stories($conn);
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Item stories | HandMeDown</title>
  <link rel="stylesheet" href="css/hmd-marketplace.css">
</head>
<body>
  <?php include __DIR__ . '/includes/hmd-header.php'; ?>
  <main class="hmd-content">
    <header class="intro"><span class="eyebrow">Stories from campus</span><h1>A little piece of campus life, passed along.</h1><p>Some things become part of the story: the mini fridge that made PG feel like home, the notes you reached for before an exam, the rack that got you through monsoon laundry. Here, students share the small moments behind the things they pass on—and hope someone else will make them part of theirs.</p><p class="field-hint">Want to add yours? Tell one small, true moment: when you reached for it, what it helped you through, or why you're ready to pass it on. Write like you're telling the next owner, not selling to them.</p></header>
    <?php if ($error !== ''): ?>
      <p class="error" role="alert"><?= hmd_escape($error) ?></p>
    <?php elseif (!$stories): ?>
      <section class="empty-state"><h2>Your story could be the first.</h2><p>No item stories have been shared yet. Think of one thing you rely on every day: what would you miss if it were gone? That might be the start of your story.</p><a class="button" href="sell.php">Tell your item's story</a></section>
    <?php else: ?>
      <section class="stories" aria-label="Item stories">
        <?php foreach ($stories as $story): ?>
          <article class="story story-card">
            <a href="listing.php?id=<?= (int) $story['id'] ?>"><img src="admin/upload/<?= rawurlencode($story['image']) ?>" alt="<?= hmd_escape($story['title']) ?>"><?php if (strpos((string) $story['image'], 'sample-') === 0): ?><span class="preview-image-label">Sample photo; not the exact item</span><?php endif; ?><span class="story-kicker"><?= hmd_escape($story['category']) ?> · In their own words</span><h2><?= hmd_escape($story['title']) ?></h2></a>
            <blockquote><?= nl2br(hmd_escape($story['story'])) ?></blockquote>
            <a class="story-read-more" href="listing.php?id=<?= (int) $story['id'] ?>">See the item <span aria-hidden="true">&nbsp;→</span></a>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
