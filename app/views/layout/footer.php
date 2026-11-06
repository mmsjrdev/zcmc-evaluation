<?php
/**
 * app/views/layout/footer.php
 *
 * The BOTTOM of every signed-in page. Closes what header.php opened and loads
 * the one small script (assets/js/ui.js) that opens and closes the phone menu.
 */
?>
    </main>

    <footer class="mx-auto w-full max-w-6xl px-4 pb-8 text-xs text-slate-400 sm:px-6">
        Zamboanga City Medical Center &middot; Material Management Section &middot; Form ZCMC-F-PROC-29
    </footer>
</div>

<script src="<?= e(asset('js/ui.js')) ?>" defer></script>
</body>
</html>
