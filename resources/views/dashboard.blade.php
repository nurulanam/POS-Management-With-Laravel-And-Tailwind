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
                    <a href="https://flowbite.com" class="flex ml-2 md:mr-24">
                        <img src="https://flowbite.com/docs/images/logo.svg" class="h-8 mr-3" alt="FlowBite Logo" />
                        <span
                            class="self-center text-xl font-semibold sm:text-2xl whitespace-nowrap dark:text-white">Flowbite</span>
                    </a>
                </div>
                <div class="flex items-center">
                    <div class="flex items-center ml-3">
                        <div class="flex space-x-4">
                            <button class="dark-toggle dark:text-orange-100 ">
                                <svg xmlns="http://www.w3.org/2000/svg" height="1.3em" viewBox="0 0 384 512"><path d="M223.5 32C100 32 0 132.3 0 256S100 480 223.5 480c60.6 0 115.5-24.2 155.8-63.4c5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-9.8 1.7-19.8 2.6-30.1 2.6c-96.9 0-175.5-78.8-175.5-176c0-65.8 36-123.1 89.3-153.3c6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-6.3-.5-12.6-.8-19-.8z"/></svg>
                            </button>
                            <button type="button"
                                class="flex text-sm bg-red-200 rounded-full focus:ring-4 focus:ring-red-300 dark:focus:ring-red-600"
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
                                <p class="text-sm text-gray-900 dark:text-white" role="none">
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
                                        role="menuitem">Dashboard</a>
                                </li>
                                <li>
                                    <a href="#"
                                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-white"
                                        role="menuitem">Settings</a>
                                </li>
                                <li>
                                    <a href="#"
                                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-white"
                                        role="menuitem">Earnings</a>
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

    <aside id="logo-sidebar"
        class="fixed top-0 left-0 z-40 w-64 h-screen pt-20 transition-transform -translate-x-full bg-white border-r border-gray-200 sm:translate-x-0 dark:bg-gray-800 dark:border-gray-700"
        aria-label="Sidebar">
        <div class="h-full px-3 pb-4 overflow-y-auto bg-white dark:bg-gray-800">
            <ul class="grid grid-cols-12 gap-3">
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/dashboardIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Dashboard</span>
                    </a>
                </li>
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/shopping-bagIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Orders</span>
                    </a>
                </li>
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/branchesIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Branches</span>
                    </a>
                </li>

                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/productIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Products</span>
                    </a>
                </li>
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/discountIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Discounts</span>
                    </a>
                </li>
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/invoiceIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Invoices</span>
                    </a>
                </li>
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/userIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Users</span>
                    </a>
                </li>
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/expencesIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Expences</span>
                    </a>
                </li>
                <li class="col-span-6 transition-all ease-linear hover:bg-orange-100 rounded-lg py-5 px-2 group">
                    <a href="#" class="flex flex-col justify-center items-center">
                        <img src="{{ asset('/assets/img/settingIcon.png') }}" alt="">
                        <span class="dark:text-orange-100 dark:group-hover:text-black mt-2">Settings</span>
                    </a>
                </li>
            </ul>
        </div>
    </aside>

    <div class="p-4 sm:ml-64">
        <div class="p-4 border-2 border-gray-200 border-dashed rounded-lg dark:border-gray-700 mt-14">
            <div class="grid grid-cols-12 gap-4 min-h-[85vh]">
                <div class="col-span-12 lg:col-span-8 bg-orange-50 min-h-full rounded-lg border p-4">
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
                </div>
                <div class="col-span-12 lg:col-span-4 bg-orange-50 min-h-full rounded-lg border p-4">

                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script>
        $(document).ready(function() {
            var isDarkModeEnabled = localStorage.getItem('darkMode');

            if (isDarkModeEnabled === 'true') {
                $('body').addClass('dark');
                $('.dark-toggle').html('<svg xmlns="http://www.w3.org/2000/svg" height="1.3em" viewBox="0 0 384 512" fill="#fff"><path d="M297.2 248.9C311.6 228.3 320 203.2 320 176c0-70.7-57.3-128-128-128S64 105.3 64 176c0 27.2 8.4 52.3 22.8 72.9c3.7 5.3 8.1 11.3 12.8 17.7l0 0c12.9 17.7 28.3 38.9 39.8 59.8c10.4 19 15.7 38.8 18.3 57.5H109c-2.2-12-5.9-23.7-11.8-34.5c-9.9-18-22.2-34.9-34.5-51.8l0 0 0 0c-5.2-7.1-10.4-14.2-15.4-21.4C27.6 247.9 16 213.3 16 176C16 78.8 94.8 0 192 0s176 78.8 176 176c0 37.3-11.6 71.9-31.4 100.3c-5 7.2-10.2 14.3-15.4 21.4l0 0 0 0c-12.3 16.8-24.6 33.7-34.5 51.8c-5.9 10.8-9.6 22.5-11.8 34.5H226.4c2.6-18.7 7.9-38.6 18.3-57.5c11.5-20.9 26.9-42.1 39.8-59.8l0 0 0 0 0 0c4.7-6.4 9-12.4 12.7-17.7zM192 128c-26.5 0-48 21.5-48 48c0 8.8-7.2 16-16 16s-16-7.2-16-16c0-44.2 35.8-80 80-80c8.8 0 16 7.2 16 16s-7.2 16-16 16zm0 384c-44.2 0-80-35.8-80-80V416H272v16c0 44.2-35.8 80-80 80z"/></svg>');
            }

            $('.dark-toggle').on('click', function() {
                if ($('body').hasClass('dark')) {
                    $('body').removeClass('dark');
                    localStorage.setItem('darkMode', 'false');
                    $('.dark-toggle').html('<svg xmlns="http://www.w3.org/2000/svg" height="1.3em" viewBox="0 0 384 512"><path d="M223.5 32C100 32 0 132.3 0 256S100 480 223.5 480c60.6 0 115.5-24.2 155.8-63.4c5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-9.8 1.7-19.8 2.6-30.1 2.6c-96.9 0-175.5-78.8-175.5-176c0-65.8 36-123.1 89.3-153.3c6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-6.3-.5-12.6-.8-19-.8z"/></svg>')
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
