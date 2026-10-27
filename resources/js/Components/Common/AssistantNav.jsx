import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, usePage } from "@inertiajs/react";

import ModalPassword from "./Modals/ModalPassword";
import ModalLogout from "./Modals/ModalLogout";
import ModalKonfigurasi from "../Assistants/Modals/ModalKonfigurasi";
import ModalKonfigurasiAi from "../Assistants/Modals/ModalKonfigurasiAi";
import ModalOpenKJ from "../Assistants/Modals/ModalOpenKJ";
import ModalActiveTP from "../Assistants/Modals/ModalActiveTP";

import { updatePassword as updateAssistantPassword } from "@/lib/routes/asisten";
import { destroy as logoutAsisten } from "@/lib/routes/auth/loginAsisten";

import profileIcon from "../../../assets/nav/Icon-Profile.svg";
import praktikumIcon from "../../../assets/nav/Icon-Praktikum.svg";
import historyIcon from "../../../assets/nav/Icon-History.svg";
import moduleIcon from "../../../assets/nav/Icon-Module.svg";
import inputSoalIcon from "../../../assets/nav/Icon-InputSoal.svg";
import leaderboardIcon from "../../../assets/nav/Icon-Leaderboard.svg";
import pollingIcon from "../../../assets/nav/Icon-Polling.svg";
import plottingIcon from "../../../assets/nav/Icon-Plotting.svg";
import nilaiIcon from "../../../assets/nav/Icon-Nilai.svg";
import roleIcon from "../../../assets/nav/Icon-Piket.svg";
import praktikanIcon from "../../../assets/nav/Icon-Praktikan.svg";
import pelanggaranIcon from "../../../assets/nav/Icon-Pelanggaran.svg";
import announcementIcon from "../../../assets/nav/Icon-Annoucement.svg";
import tpModuleIcon from "../../../assets/nav/Icon-TP.svg";
import jawabanTP from "../../../assets/nav/Icon-Rating.svg";
import konfigurasiIcon from "../../../assets/nav/Icon-Konfigurasi.svg";
import changePassIcon from "../../../assets/nav/Icon-GantiPassword.svg";
import logoutIcon from "../../../assets/nav/Icon-Logout.svg";

const STORAGE_KEY = "assistantNavCollapsed";
const ADMIN_ROLES = ["SOFTWARE", "KORDAS", "WAKORDAS", "ADMIN"];
const PRAKTIKUM_ROLES = ["ATC", "RDC"];

const BASE_NAV_ITEMS = [
    {
        id: "manage-profile",
        permission: "manage-profile",
        href: "/assistant",
        label: "Profile",
        icon: profileIcon,
        components: ["Assistants/ProfileAssistant"],
        paths: ["/assistant"],
    },
    {
        id: "manage-praktikum",
        permission: "see-history",
        href: "/start-praktikum",
        label: "Start Praktikum",
        icon: praktikumIcon,
        components: ["Assistants/StartPraktikum"],
        paths: ["/start-praktikum"],
    },
    {
        id: "see-history",
        permission: "see-history",
        href: "/history",
        label: "History Praktikum",
        icon: historyIcon,
        components: ["Assistants/HistoryPraktikum"],
        paths: ["/history"],
    },
    {
        id: "nilai-praktikan",
        permission: "nilai-praktikan",
        href: "/nilai-praktikan",
        label: "Nilai Praktikan",
        icon: nilaiIcon,
        components: ["Assistants/NilaiPraktikan"],
        paths: ["/nilai-praktikan"],
    },
    {
        id: "lihat-tp",
        permission: "check-tugas-pendahuluan",
        href: "/lihat-tp",
        label: "Lihat TP",
        icon: jawabanTP,
        components: ["Assistants/LihatTP", "Assistants/ResultLihatTP"],
        paths: ["/lihat-tp", "/jawaban-tp"],
    },
    {
        id: "see-soal",
        permission: "see-soal",
        href: "/soal",
        label: "Soal",
        icon: inputSoalIcon,
        components: ["Assistants/SoalPraktikum"],
        paths: ["/soal"],
    },
    {
        id: "manage-modul",
        permission: "manage-modul",
        href: "/modul",
        label: "Manage Modul",
        icon: moduleIcon,
        components: ["Assistants/ModulePraktikum"],
        paths: ["/modul"],
    },
    {
        id: "see-plot",
        permission: "see-plot",
        href: "/plottingan",
        label: "Plotting Jadwal",
        icon: plottingIcon,
        components: ["Assistants/PlottingAssistant"],
        paths: ["/plottingan"],
    },
    {
        id: "leaderboard-ranking",
        permission: "nilai-praktikan",
        href: "/leaderboard-ranking",
        label: "Leaderboard Ranking",
        icon: leaderboardIcon,
        components: ["Assistants/LeaderboardRanking"],
        paths: ["/leaderboard-ranking"],
    },
    {
        id: "see-polling",
        permission: "see-polling",
        href: "/polling",
        label: "Polling Assistant",
        icon: pollingIcon,
        components: ["Assistants/PollingAssistant"],
        paths: ["/polling"],
    },
    {
        id: "see-pelanggaran",
        permission: "see-pelanggaran",
        href: "/pelanggaran",
        label: "Pelanggaran",
        icon: pelanggaranIcon,
        components: ["Assistants/PelanggaranAssistant"],
        paths: ["/pelanggaran"],
    },
    {
        id: "manage-role",
        permission: "manage-role",
        allowedRoles: ADMIN_ROLES,
        href: "/manage-role",
        label: "Manage Asisten",
        icon: roleIcon,
        components: ["Assistants/ManageRole"],
        paths: ["/manage-role"],
    },
    {
        id: "set-praktikan",
        href: "/set-praktikan",
        label: "Set Praktikan",
        icon: praktikanIcon,
        components: ["Assistants/SetPraktikan"],
        paths: ["/set-praktikan"],
    },
    {
        id: "manage-praktikan",
        permission: ["praktikan-regist", "manage-role"],
        href: "/manage-praktikan",
        label: "Manage Praktikan",
        icon: praktikanIcon,
        components: ["Assistants/ManagePraktikan"],
        paths: ["/manage-praktikan"],
    },
];

const navLinkBaseClass =
    "group relative flex w-full items-center gap-3 rounded-depth-md px-2 py-2 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--depth-color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--depth-color-background)] hover:-translate-y-0.5 hover:shadow-depth-md";
const activeLinkClass = "bg-[var(--depth-color-primary)] text-white shadow-depth-md hover:bg-[var(--depth-color-primary)]";
const inactiveLinkClass = "text-depth-primary hover:bg-depth-interactive";
const navIconClass = "h-6 w-6 flex-shrink-0 transition-all duration-300";

const genericHamburgerLine = "h-1 w-6 my-1 rounded-full bg-[var(--depth-text-primary)] transition ease transform duration-300";

export default function AssisstantNav({ asisten, permission_name = [], roleName }) {
    const { url, component } = usePage();

    const getInitialCollapsed = useCallback(() => {
        if (typeof window === "undefined") {
            return true;
        }

        const stored = window.localStorage.getItem(STORAGE_KEY);
        return stored !== null ? stored === "true" : true;
    }, []);

    const [isCollapsed, setIsCollapsed] = useState(getInitialCollapsed);
    const [isAnimating, setIsAnimating] = useState(false);
    const [isPasswordModalOpen, setIsPasswordModalOpen] = useState(false);
    const [showLogoutModal, setShowLogoutModal] = useState(false);
    const [showConfigModal, setShowConfigModal] = useState(false);
    const [showAiConfigModal, setShowAiConfigModal] = useState(false);
    const [showOpenKJ, setShowOpenKJ] = useState(false);
    const [showOpenTP, setShowOpenTP] = useState(false);
    const [isOpen, setIsOpen] = useState(() => !getInitialCollapsed());

    useEffect(() => {
        if (typeof window === "undefined") {
            return;
        }

        window.localStorage.setItem(STORAGE_KEY, String(isCollapsed));
    }, [isCollapsed]);

    const toggleSidebar = () => {
        setIsOpen((prev) => !prev);
        if (!isCollapsed) {
            setIsAnimating(true);
            setTimeout(() => {
                setIsCollapsed((prev) => !prev);
                setIsAnimating(false);
            }, 300);
        } else {
            setIsCollapsed((prev) => !prev);
        }
    };

    const permissionSet = useMemo(() => new Set(permission_name), [permission_name]);
    const normalizedRole = roleName?.toUpperCase() ?? null;

    const hasPermission = useCallback(
        (permission) => {
            if (!permission || (Array.isArray(permission) && permission.length === 0)) {
                return true;
            }

            const permissions = Array.isArray(permission) ? permission : [permission];
            return permissions.some((name) => permissionSet.has(name));
        },
        [permissionSet],
    );

    const isRoleAllowed = useCallback(
        (allowedRoles) => {
            if (!allowedRoles || allowedRoles.length === 0) {
                return true;
            }

            if (!normalizedRole) {
                return false;
            }

            return allowedRoles.map((role) => role.toUpperCase()).includes(normalizedRole);
        },
        [normalizedRole],
    );

    const canAccess = useCallback(
        (permission, allowedRoles) => hasPermission(permission) && isRoleAllowed(allowedRoles),
        [hasPermission, isRoleAllowed],
    );

    const isActiveItem = useCallback(
        (item) => {
            if (!item?.href) {
                return false;
            }

            const matchesComponent = item.components?.some((name) => component === name);
            const matchesPath = item.paths?.some((path) => {
                if (!url || !path) {
                    return false;
                }

                if (path === '/') {
                    return url === '/';
                }

                if (url === path) {
                    return true;
                }

                return url.startsWith(`${path}/`);
            });

            return Boolean(matchesComponent || matchesPath);
        },
        [component, url],
    );

    const availableNavItems = useMemo(
        () => BASE_NAV_ITEMS.filter((item) => canAccess(item.permission, item.allowedRoles)),
        [canAccess],
    );

    const footerActions = useMemo(
        () => [
            {
                id: "config",
                label: "Configuration",
                icon: konfigurasiIcon,
                permission: "lms-configuration",
                allowedRoles: ADMIN_ROLES,
                onSelect: () => setShowConfigModal(true),
            },
            {
                id: "ai-provider",
                label: "Provider AI",
                icon: konfigurasiIcon,
                permission: "manage-ai-gate",
                allowedRoles: ADMIN_ROLES,
                onSelect: () => setShowAiConfigModal(true),
            },
            {
                id: "active-tp",
                label: "Tugas Pendahuluan",
                icon: tpModuleIcon,
                permission: "check-tugas-pendahuluan",
                allowedRoles: [...ADMIN_ROLES, ...PRAKTIKUM_ROLES],
                onSelect: () => setShowOpenTP(true),
            },
            {
                id: "unlock-jawaban",
                label: "Open Jawaban",
                icon: announcementIcon,
                permission: "unlock-jawaban",
                allowedRoles: ADMIN_ROLES,
                onSelect: () => setShowOpenKJ(true),
            },
            {
                id: "change-password",
                label: "Change Password",
                icon: changePassIcon,
                onSelect: () => setIsPasswordModalOpen(true),
            },
            {
                id: "logout",
                label: "Logout",
                icon: logoutIcon,
                onSelect: () => setShowLogoutModal(true),
            },
        ].filter((action) => canAccess(action.permission, action.allowedRoles)),
        [canAccess],
    );

    const labelVisibilityClass = isCollapsed
        ? "opacity-0 delay-0"
        : isAnimating
            ? "opacity-0"
            : "opacity-100 delay-300";

    const navLabelBaseClass = `text-sm font-medium transition-opacity duration-300 whitespace-nowrap ${labelVisibilityClass}`;

    const getIconFilterClass = (isActive) => (isActive ? "nav-icon-filter-active" : "nav-icon-filter");

    const renderNavLink = (item) => {
        const isActive = isActiveItem(item);
        const linkClasses = `${navLinkBaseClass} ${isActive ? activeLinkClass : inactiveLinkClass}`;
        const iconClasses = `${navIconClass} ${isActive ? "opacity-100" : "opacity-70 group-hover:opacity-100"
            } ${getIconFilterClass(isActive)}`;
        const labelClasses = `${navLabelBaseClass} ${isActive ? "text-white" : "text-depth-primary"}`;
        const altText = item.alt ?? item.label.toLowerCase();

        return (
            <li key={item.id} id={item.id}>
                <Link href={item.href} className={linkClasses}>
                    {isActive && (
                        <span
                            aria-hidden="true"
                            className="absolute inset-y-1 right-1 w-1 translate-x-1 rounded-depth-full bg-white/70"
                        />
                    )}
                    <img className={iconClasses} src={item.icon} alt={altText} />
                    <span className={labelClasses}>{item.label}</span>
                </Link>
            </li>
        );
    };

    const renderActionButton = (action) => (
        <li key={action.id} id={action.id}>
            <button type="button" className={`${navLinkBaseClass} ${inactiveLinkClass}`} onClick={action.onSelect}>
                <img
                    className={`${navIconClass} opacity-80 group-hover:opacity-100 ${getIconFilterClass(false)}`}
                    src={action.icon}
                    alt={action.label.toLowerCase()}
                />
                <span className={`${navLabelBaseClass} text-depth-primary`}>{action.label}</span>
            </button>
        </li>
    );

    return (
        <>
            <nav className="flex h-screen items-start">
                <div
                    className={`flex h-[91vh] flex-col rounded-depth-lg border border-depth glass-surface text-left text-depth-primary font-depth font-semibold shadow-depth-lg transition-all duration-300 ${isCollapsed ? "w-12" : "w-[260px]"
                        }`}
                >
                    <div className={`relative flex items-center ${isCollapsed ? "justify-center" : "justify-end"}`}>
                        <button
                            type="button"
                            className="group flex flex-col items-center justify-center p-3"
                            onClick={toggleSidebar}
                            aria-label="Toggle navigation"
                        >
                            <div
                                className={`${genericHamburgerLine} ${isOpen ? "translate-y-3 rotate-45" : "translate-y-1 group-hover:translate-y-0"
                                    }`}
                            />
                            <div className={`${genericHamburgerLine} ${isOpen ? "opacity-0" : "opacity-100"}`} />
                            <div
                                className={`${genericHamburgerLine} ${isOpen ? "-translate-y-3 -rotate-45" : "-translate-y-1 group-hover:translate-y-0"
                                    }`}
                            />
                        </button>
                    </div>

                    <div className="flex flex-1 flex-col overflow-hidden -mt-4">
                        <div className="flex flex-1 flex-col overflow-y-auto scrollbar-hide">
                            <ul className={`flex flex-col gap-1 py-2 ${isCollapsed ? "px-1" : "px-4"}`}>
                                {availableNavItems.map((item) => renderNavLink(item))}
                            </ul>

                            <ul className={`mt-auto flex flex-col gap-1 py-6 ${isCollapsed ? "px-1" : "px-4"}`}>
                                {footerActions.map((action) => renderActionButton(action))}
                            </ul>
                        </div>
                    </div>
                </div>
            </nav>

            <ModalPassword
                isOpen={isPasswordModalOpen}
                onClose={() => setIsPasswordModalOpen(false)}
                updatePasswordAction={updateAssistantPassword}
                userType="asisten"
            />

            <ModalLogout
                isOpen={showLogoutModal}
                onClose={() => setShowLogoutModal(false)}
                logoutAction={logoutAsisten}
                onLogoutSuccess={() => {
                    window.location.href = "/";
                }}
            />

            {showConfigModal && <ModalKonfigurasi onClose={() => setShowConfigModal(false)} />}
            {showAiConfigModal && <ModalKonfigurasiAi onClose={() => setShowAiConfigModal(false)} />}
            {showOpenKJ && <ModalOpenKJ onClose={() => setShowOpenKJ(false)} />}
            {showOpenTP && <ModalActiveTP onClose={() => setShowOpenTP(false)} />}
        </>
    );
}
