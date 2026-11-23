<header class="h-[68px] bg-slate-900">
    <div class="wrap flex h-full items-center justify-between">
        <a
            href="/index.php"
            aria-label="NovaPS home"
            class="group flex items-center gap-2.5 font-display text-2xl font-bold text-white transition-transform duration-150 active:scale-95"
        >
            <span
                class="grid size-[30px] place-items-center rounded-[7px] bg-cyan-400 transition-transform duration-300 ease-[cubic-bezier(.3,1.6,.5,1)] group-hover:-rotate-[8deg] group-hover:scale-110"
            >
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="#0f172a"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <rect x="2" y="6" width="20" height="12" rx="3"/>
                    <path d="M6 12h4M8 10v4"/>
                    <circle cx="15.5" cy="11" r=".6"/>
                    <circle cx="17.5" cy="13" r=".6"/>
                </svg>
            </span>
            Nova<span class="text-cyan-400">PS</span>
        </a>

        <nav class="flex items-center">
            <?php if ($authPage === 'signup'): ?>
                <a
                    href="/pages/login.php"
                    class="btn btn-outline rounded px-[18px] py-2 text-[15px] font-semibold"
                >Login</a>
            <?php else: ?>
                <a
                    href="/pages/signup.php"
                    class="btn btn-outline rounded px-[18px] py-2 text-[15px] font-semibold"
                >Sign Up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
