<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Doc</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="">
{{-- nav start  --}}
    <nav class="fixed top-0 z-50 w-full bg-white border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
        <div class="px-3 py-3 lg:px-5 lg:pl-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center justify-start">
                    <button data-drawer-target="logo-sidebar" data-drawer-toggle="logo-sidebar"
                        aria-controls="logo-sidebar" type="button"
                        class="inline-flex items-center p-2 text-sm text-gray-500 rounded-lg sm:hidden hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600">
                        <span class="sr-only">Open sidebar</span>
                        <svg class="w-6 h-6" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg">
                            <path clip-rule="evenodd" fill-rule="evenodd"
                                d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10z">
                            </path>
                        </svg>
                    </button>
                    <a href="#" class="flex ml-2 md:mr-24">
                        <img src="{{ asset('assets/img/logo/logo.png') }}" class="h-8 mr-3" alt="FlowBite Logo" />
                        <span
                            class="self-center text-xl font-bold whitespace-nowrap text-orange-400 dark:text-white">Sunset Sales</span>
                    </a>
                </div>
                <div class="flex items-center">
                    <div class="flex items-center ml-3">
                        <div class="flex space-x-4">
                            <button class="dark-toggle dark:text-orange-100 ">
                                <svg xmlns="http://www.w3.org/2000/svg" height="1.3em" viewBox="0 0 384 512"><path d="M144.7 98.7c-21 34.1-33.1 74.3-33.1 117.3c0 98 62.8 181.4 150.4 211.7c-12.4 2.8-25.3 4.3-38.6 4.3C126.6 432 48 353.3 48 256c0-68.9 39.4-128.4 96.8-157.3zm62.1-66C91.1 41.2 0 137.9 0 256C0 379.7 100 480 223.5 480c47.8 0 92-15 128.4-40.6c1.9-1.3 3.7-2.7 5.5-4c4.8-3.6 9.4-7.4 13.9-11.4c2.7-2.4 5.3-4.8 7.9-7.3c5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-3.7 .6-7.4 1.2-11.1 1.6c-5 .5-10.1 .9-15.3 1c-1.2 0-2.5 0-3.7 0c-.1 0-.2 0-.3 0c-96.8-.2-175.2-78.9-175.2-176c0-54.8 24.9-103.7 64.1-136c1-.9 2.1-1.7 3.2-2.6c4-3.2 8.2-6.2 12.5-9c3.1-2 6.3-4 9.6-5.8c6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-3.6-.3-7.1-.5-10.7-.6c-2.7-.1-5.5-.1-8.2-.1c-3.3 0-6.5 .1-9.8 .2c-2.3 .1-4.6 .2-6.9 .4z"/></svg>
                            </button>
                            <button type="button"
                                class="flex text-sm bg-red-200 rounded-full focus:ring-4 focus:ring-red-300"
                                aria-expanded="false" data-dropdown-toggle="dropdown-user">
                                <span class="sr-only">Open user menu</span>
                                <img class="w-8 h-8 rounded-full"
                                    src="https://flowbite.com/docs/images/people/profile-picture-5.jpg"
                                    alt="user photo">
                            </button>
                        </div>
                        <div class="z-50 hidden my-4 text-base list-none bg-white divide-y divide-gray-100 rounded shadow dark:bg-gray-700 dark:divide-gray-600"
                            id="dropdown-user">
                            <div class="px-4 py-3" role="none">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white" role="none">
                                    Neil Sims
                                </p>
                                <p class="text-sm font-medium text-gray-900 truncate dark:text-gray-300" role="none">
                                    neil.sims@flowbite.com
                                </p>
                            </div>
                            <ul class="py-1" role="none">
                                <li>
                                    <a href="#"
                                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-white"
                                        role="menuitem">Profile</a>
                                </li>
                                <li>
                                    <a href="#"
                                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-white"
                                        role="menuitem">Sign out</a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    {{-- sidebar start --}}
    <aside id="logo-sidebar"
        class="fixed top-0 left-0 z-40 w-64 h-screen pt-20 transition-transform -translate-x-full bg-white border-r border-gray-200 sm:translate-x-0 dark:bg-gray-800 dark:border-gray-700"
        aria-label="Sidebar">
        <div class="h-full px-3 pb-4 overflow-y-auto bg-white dark:bg-gray-800">
            <ul class="grid grid-cols-12 gap-3">
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/dashboardIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/shopping-bagIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Orders</span>
                    </a>
                </li>
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/branchesIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Branches</span>
                    </a>
                </li>

                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/productIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Products</span>
                    </a>
                </li>
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/discountIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Discounts</span>
                    </a>
                </li>
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/invoiceIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Invoices</span>
                    </a>
                </li>
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/userIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Users</span>
                    </a>
                </li>
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/expencesIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Expences</span>
                    </a>
                </li>
                <li class="sidebar-list-item group">
                    <a href="#" class="sidebar-list-link">
                        <img src="{{ asset('/assets/img/settingIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Settings</span>
                    </a>
                </li>
            </ul>
        </div>
    </aside>

    <div class="p-4 sm:ml-64 dark:bg-slate-700">
        <div class="p-4 border-2 border-gray-200 border-dashed rounded-lg dark:border-orange-100 mt-14">
            <div class="grid grid-cols-12 gap-4 min-h-[88vh]">
                {{-- main-content start  --}}
                <div class="col-span-12 lg:col-span-8 bg-orange-50 dark:bg-gray-800 min-h-full rounded-lg border dark:border-gray-500 p-4">
                    <form id="topSearch">
                        <label for="default-search"
                            class="mb-2 text-sm font-medium text-gray-900 sr-only dark:text-white">Search</label>
                        <div class="relative">
                            <input type="search" id="default-search"
                                class="block w-full p-4 text-sm text-gray-900 border border-gray-300 rounded-lg bg-gray-50 focus:ring-orange-400 focus:border-orange-400 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-orange-400 dark:focus:border-orange-400"
                                placeholder="Search by Product name, Stock Key..." required>
                            <button type="submit"
                                class="text-white absolute flex items-center space-x-1 right-2.5 bottom-2.5 bg-red-500 hover:bg-red-600 focus:ring-4 focus:outline-none focus:ring-orange-300 font-medium rounded-lg text-sm px-4 py-2 dark:bg-red-500 dark:hover:bg-red-600 dark:focus:ring-red-400">
                                <svg class="w-4 h-4 text-red-200" aria-hidden="true"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                        stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z" />
                                </svg><span class="hidden md:block">Search</span></button>
                        </div>
                    </form>
                    <div class="categories">
                        <div class="owl-carousel owl-theme my-2">
                            <div class="category-item" data-categroy="name">
                                <p class="text-gray-800">Dairy product</p>
                            </div>
                            <div class="category-item" data-categroy="name">
                                <p class="text-gray-800">Vegetables</p>
                            </div>
                            <div class="category-item" data-categroy="name">
                                <p class="text-gray-800">Fruits</p>
                            </div>
                            <div class="category-item" data-categroy="name">
                                <p class="text-gray-800">Meat</p>
                            </div>
                            <div class="category-item" data-categroy="name">
                                <p class="text-gray-800">Grain</p>
                            </div>
                            <div class="category-item" data-categroy="name">
                                <p class="text-gray-800">Fish</p>
                            </div>
                            <div class="category-item" data-categroy="name">
                                <p class="text-gray-800">Desserts</p>
                            </div>
                          </div>
                    </div>
                </div>
                {{-- cart start  --}}
                <div class="col-span-12 lg:col-span-4 bg-orange-100 dark:bg-gray-800 min-h-full rounded-lg border dark:border-gray-500 p-4">
                    <h2 class="text-gray-800 dark:text-orange-100 text-xl font-medium border pb-3 border-orange-300 dark:border-gray-500 border-t-0 border-l-0 border-r-0 border-b-[1] ">Current Sale</h2>
                    <div class="cart-info">
                        <div class="sub-total gird grid-cols-12">
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script>
        $(document).ready(function() {
            $('.owl-carousel').owlCarousel({
                margin: 8,
                loop: true,
                autoplay: true,
                autoplayHoverPause: true,
                dots : false,
                responsive : {
                    0 : {
                        items:1,
                    },
                    640 : {
                        items:2,
                    },
                    768 : {
                        items:3,
                    },
                    1024 : {
                        items:4,
                    }
                }
            });


            var isDarkModeEnabled = localStorage.getItem('darkMode');

            if (isDarkModeEnabled === 'true') {
                $('body').addClass('dark');
                $('.dark-toggle').html('<svg xmlns="http://www.w3.org/2000/svg" height="1.3em" viewBox="0 0 384 512" fill="#fff"><path d="M297.2 248.9C311.6 228.3 320 203.2 320 176c0-70.7-57.3-128-128-128S64 105.3 64 176c0 27.2 8.4 52.3 22.8 72.9c3.7 5.3 8.1 11.3 12.8 17.7l0 0c12.9 17.7 28.3 38.9 39.8 59.8c10.4 19 15.7 38.8 18.3 57.5H109c-2.2-12-5.9-23.7-11.8-34.5c-9.9-18-22.2-34.9-34.5-51.8l0 0 0 0c-5.2-7.1-10.4-14.2-15.4-21.4C27.6 247.9 16 213.3 16 176C16 78.8 94.8 0 192 0s176 78.8 176 176c0 37.3-11.6 71.9-31.4 100.3c-5 7.2-10.2 14.3-15.4 21.4l0 0 0 0c-12.3 16.8-24.6 33.7-34.5 51.8c-5.9 10.8-9.6 22.5-11.8 34.5H226.4c2.6-18.7 7.9-38.6 18.3-57.5c11.5-20.9 26.9-42.1 39.8-59.8l0 0 0 0 0 0c4.7-6.4 9-12.4 12.7-17.7zM192 128c-26.5 0-48 21.5-48 48c0 8.8-7.2 16-16 16s-16-7.2-16-16c0-44.2 35.8-80 80-80c8.8 0 16 7.2 16 16s-7.2 16-16 16zm0 384c-44.2 0-80-35.8-80-80V416H272v16c0 44.2-35.8 80-80 80z"/></svg>');
            }

            $('.dark-toggle').on('click', function() {
                if ($('body').hasClass('dark')) {
                    $('body').removeClass('dark');
                    localStorage.setItem('darkMode', 'false');
                    $('.dark-toggle').html('<svg xmlns="http://www.w3.org/2000/svg" height="1.3em" viewBox="0 0 384 512"><path d="M144.7 98.7c-21 34.1-33.1 74.3-33.1 117.3c0 98 62.8 181.4 150.4 211.7c-12.4 2.8-25.3 4.3-38.6 4.3C126.6 432 48 353.3 48 256c0-68.9 39.4-128.4 96.8-157.3zm62.1-66C91.1 41.2 0 137.9 0 256C0 379.7 100 480 223.5 480c47.8 0 92-15 128.4-40.6c1.9-1.3 3.7-2.7 5.5-4c4.8-3.6 9.4-7.4 13.9-11.4c2.7-2.4 5.3-4.8 7.9-7.3c5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-3.7 .6-7.4 1.2-11.1 1.6c-5 .5-10.1 .9-15.3 1c-1.2 0-2.5 0-3.7 0c-.1 0-.2 0-.3 0c-96.8-.2-175.2-78.9-175.2-176c0-54.8 24.9-103.7 64.1-136c1-.9 2.1-1.7 3.2-2.6c4-3.2 8.2-6.2 12.5-9c3.1-2 6.3-4 9.6-5.8c6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-3.6-.3-7.1-.5-10.7-.6c-2.7-.1-5.5-.1-8.2-.1c-3.3 0-6.5 .1-9.8 .2c-2.3 .1-4.6 .2-6.9 .4z"/></svg>')
                } else {
                    $('body').addClass('dark');
                $('.dark-toggle').html('<svg xmlns="http://www.w3.org/2000/svg" height="1.3em" viewBox="0 0 384 512" fill="#fff"><path d="M297.2 248.9C311.6 228.3 320 203.2 320 176c0-70.7-57.3-128-128-128S64 105.3 64 176c0 27.2 8.4 52.3 22.8 72.9c3.7 5.3 8.1 11.3 12.8 17.7l0 0c12.9 17.7 28.3 38.9 39.8 59.8c10.4 19 15.7 38.8 18.3 57.5H109c-2.2-12-5.9-23.7-11.8-34.5c-9.9-18-22.2-34.9-34.5-51.8l0 0 0 0c-5.2-7.1-10.4-14.2-15.4-21.4C27.6 247.9 16 213.3 16 176C16 78.8 94.8 0 192 0s176 78.8 176 176c0 37.3-11.6 71.9-31.4 100.3c-5 7.2-10.2 14.3-15.4 21.4l0 0 0 0c-12.3 16.8-24.6 33.7-34.5 51.8c-5.9 10.8-9.6 22.5-11.8 34.5H226.4c2.6-18.7 7.9-38.6 18.3-57.5c11.5-20.9 26.9-42.1 39.8-59.8l0 0 0 0 0 0c4.7-6.4 9-12.4 12.7-17.7zM192 128c-26.5 0-48 21.5-48 48c0 8.8-7.2 16-16 16s-16-7.2-16-16c0-44.2 35.8-80 80-80c8.8 0 16 7.2 16 16s-7.2 16-16 16zm0 384c-44.2 0-80-35.8-80-80V416H272v16c0 44.2-35.8 80-80 80z"/></svg>');
                    localStorage.setItem('darkMode', 'true');
                }
            });
        });
    </script>

</body>

</html>
