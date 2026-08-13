<header class="site-header">

    <div class="header-inner">

        <a
            class="logo"
            href="{{ route('home') }}"
            aria-label="Xurvexa home"
        >
            XUR<span>VEXA</span>
        </a>

        @if($showSearch ?? false)

            <form
                class="header-search"
                method="GET"
                action="{{ route('videos.index') }}"
            >

                <input
                    type="search"
                    name="q"
                    placeholder="Search videos..."
                    aria-label="Search videos"
                >

                <button type="submit">
                    Search
                </button>

            </form>

        @endif

        <nav
            class="main-nav"
            aria-label="Main navigation"
        >

            <a href="{{ route('home') }}">
                Home
            </a>

            <a href="{{ route('videos.index') }}">
                Videos
            </a>

        </nav>

    </div>

</header>
