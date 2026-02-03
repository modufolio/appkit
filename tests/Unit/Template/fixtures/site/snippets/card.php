<div class="card">
    <h2><?= $title ?? 'Untitled' ?></h2>
    <?= $this->snippet('button', ['text' => $buttonText ?? 'Click']) ?>
</div>
