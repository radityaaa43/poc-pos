import { describe, it, expect, vi, beforeAll } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import i18n from "@/i18n";
import ProductGrid from "./ProductGrid";

beforeAll(() => i18n.changeLanguage("id"));

const kopi = {
    id: 1,
    title: "Kopi Susu",
    barcode: "KS-01",
    category_id: 1,
    sell_price: 15000,
    stock: 3,
};
const teh = {
    id: 2,
    title: "Teh Manis",
    barcode: null,
    category_id: 2,
    sell_price: 8000,
    stock: 20,
};
const roti = { id: 3, title: "Roti", category_id: 2, sell_price: 12000, stock: 0 };
const categories = [
    { id: 1, name: "Minuman" },
    { id: 2, name: "Makanan" },
];

function renderGrid(props = {}) {
    const handlers = {
        onCategoryChange: vi.fn(),
        onSearchChange: vi.fn(),
        onSearch: vi.fn(),
        onAddToCart: vi.fn(),
    };
    render(
        <ProductGrid
            products={[kopi, teh, roti]}
            categories={categories}
            selectedCategory={null}
            searchQuery=""
            isSearching={false}
            {...handlers}
            {...props}
        />
    );
    return handlers;
}

const title = (name) => screen.queryByRole("heading", { name });

// The product image button is named after the image alt (the title) plus its overlays.
const cardButton = (name) =>
    screen.getByRole("button", { name: new RegExp(`^${name}`) });

describe("ProductGrid", () => {
    it('shows all products for the "Semua" tab', () => {
        renderGrid({ selectedCategory: null });

        expect(title("Kopi Susu")).toBeInTheDocument();
        expect(title("Teh Manis")).toBeInTheDocument();
        expect(title("Roti")).toBeInTheDocument();
    });

    it("filters by category", () => {
        renderGrid({ selectedCategory: "2" });

        expect(title("Kopi Susu")).not.toBeInTheDocument();
        expect(title("Teh Manis")).toBeInTheDocument();
        expect(title("Roti")).toBeInTheDocument();
    });

    it("search matches title and barcode, case-insensitively", () => {
        const { rerender } = render(
            <ProductGrid
                products={[kopi, teh, roti]}
                categories={categories}
                selectedCategory={null}
                searchQuery="kopi"
            />
        );
        expect(title("Kopi Susu")).toBeInTheDocument();
        expect(title("Teh Manis")).not.toBeInTheDocument();
        expect(title("Roti")).not.toBeInTheDocument();

        rerender(
            <ProductGrid
                products={[kopi, teh, roti]}
                categories={categories}
                selectedCategory={null}
                searchQuery="ks-01"
            />
        );
        expect(title("Kopi Susu")).toBeInTheDocument();
        expect(title("Teh Manis")).not.toBeInTheDocument();

        rerender(
            <ProductGrid
                products={[kopi, teh, roti]}
                categories={categories}
                selectedCategory={null}
                searchQuery="xyz"
            />
        );
        expect(screen.getByText("Produk tidak ditemukan")).toBeInTheDocument();
    });

    it("shows the empty message when there are no products and no query", () => {
        renderGrid({ products: [] });

        expect(screen.getByText("Tidak ada produk")).toBeInTheDocument();
    });

    it("category tabs call onCategoryChange", async () => {
        const user = userEvent.setup();
        const { onCategoryChange } = renderGrid({ selectedCategory: 1 });

        await user.click(screen.getByRole("button", { name: "Semua" }));
        expect(onCategoryChange).toHaveBeenLastCalledWith(null);

        await user.click(screen.getByRole("button", { name: "Makanan" }));
        expect(onCategoryChange).toHaveBeenLastCalledWith(2);
    });

    it("stock states: low stock badge, and out-of-stock is disabled", async () => {
        const user = userEvent.setup();
        const { onAddToCart } = renderGrid();

        expect(screen.getByText("Sisa 3")).toBeInTheDocument();
        expect(screen.queryByText("Sisa 20")).not.toBeInTheDocument();
        expect(screen.getByText("Habis")).toBeInTheDocument();

        const rotiButton = cardButton("Roti");
        expect(rotiButton).toBeDisabled();
        await user.click(rotiButton);
        expect(onAddToCart).not.toHaveBeenCalled();
    });

    it("shows a menu with a required group sold out as Habis and not tappable", async () => {
        await i18n.changeLanguage("id");
        renderGrid({ products: [{ ...kopi, modifiers_sold_out: true }] });

        expect(screen.getByText("Habis")).toBeInTheDocument();
        expect(cardButton("Kopi Susu")).toBeDisabled();
    });

    it("shows a sold-out recipe menu as Habis and does not add it", async () => {
        const user = userEvent.setup();
        const americano = {
            id: 9,
            title: "Americano",
            category_id: 1,
            sell_price: 22000,
            stock: 0,
            is_composite: true,
            sold_out: true,
        };
        const { onAddToCart } = renderGrid({ products: [americano] });

        expect(screen.getByText("Habis")).toBeInTheDocument();
        const card = cardButton("Americano");
        expect(card).toBeDisabled();
        await user.click(card);
        expect(onAddToCart).not.toHaveBeenCalled();
    });

    it("clicking an in-stock product adds it with the base unit", async () => {
        const user = userEvent.setup();
        const { onAddToCart } = renderGrid();

        await user.click(cardButton("Kopi Susu"));

        expect(onAddToCart).toHaveBeenCalledTimes(1);
        expect(onAddToCart).toHaveBeenCalledWith(kopi, null);
    });

    it("disables the card while that product is being added", () => {
        renderGrid({ addingProductId: 1 });

        expect(cardButton("Kopi Susu")).toBeDisabled();
        expect(cardButton("Teh Manis")).toBeEnabled();
    });

    describe("promo price", () => {
        it("shows the promo price with the base price struck through", () => {
            const promo = {
                ...kopi,
                pricing_badge: { label: "Promo", promo_price: 12000, base_price: 15000 },
            };
            renderGrid({ products: [promo] });

            expect(screen.getByText("Rp 12.000")).toBeInTheDocument();
            expect(screen.getByText("Rp 15.000")).toHaveClass("line-through");
            expect(screen.queryByText("Promo tersedia")).not.toBeInTheDocument();
        });

        it("shows no promo price when promo_price is not below the base price", () => {
            const promo = {
                ...kopi,
                pricing_badge: { label: "Promo", promo_price: 15000, base_price: 15000 },
            };
            renderGrid({ products: [promo] });

            expect(screen.getByText("Rp 15.000")).not.toHaveClass("line-through");
            expect(screen.getAllByText(/^Rp\s/)).toHaveLength(1);
            expect(screen.getByText("Promo tersedia")).toBeInTheDocument();
        });

        it("shows no promo price when promo_price is above the base price", () => {
            const promo = {
                ...kopi,
                pricing_badge: { label: "Promo", promo_price: 16000, base_price: 15000 },
            };
            renderGrid({ products: [promo] });

            expect(screen.getByText("Rp 15.000")).not.toHaveClass("line-through");
            expect(screen.getAllByText(/^Rp\s/)).toHaveLength(1);
        });
    });

    describe("unit chips", () => {
        const box = { unit_id: 9, code: "BOX", sell_price: 140000, is_base: false };
        const withUnits = { ...kopi, units: [box] };

        it("renders the Dasar chip plus a chip per non-base unit only", () => {
            const base = { unit_id: 8, code: "PCS", sell_price: 15000, is_base: true };
            renderGrid({ products: [{ ...kopi, units: [base, box] }] });

            expect(screen.getByRole("button", { name: "Dasar" })).toBeInTheDocument();
            expect(screen.getByRole("button", { name: "BOX" })).toBeInTheDocument();
            expect(screen.queryByRole("button", { name: "PCS" })).not.toBeInTheDocument();
        });

        it("selecting a unit shows its price and adds it; Dasar returns to base", async () => {
            const user = userEvent.setup();
            const { onAddToCart } = renderGrid({ products: [withUnits] });

            await user.click(screen.getByRole("button", { name: "BOX" }));
            expect(screen.getByText(/^Rp 140\.000/)).toHaveTextContent("/BOX");

            await user.click(cardButton("Kopi Susu"));
            expect(onAddToCart).toHaveBeenLastCalledWith(withUnits, box);

            await user.click(screen.getByRole("button", { name: "Dasar" }));
            expect(screen.getByText("Rp 15.000")).toBeInTheDocument();
            expect(screen.queryByText(/\/BOX/)).not.toBeInTheDocument();

            await user.click(cardButton("Kopi Susu"));
            expect(onAddToCart).toHaveBeenLastCalledWith(withUnits, null);
        });
    });

    describe("search input", () => {
        it("typing calls onSearchChange and Enter calls onSearch", async () => {
            const user = userEvent.setup();
            const { onSearchChange, onSearch } = renderGrid();
            const input = screen.getByPlaceholderText(/Cari produk/);

            await user.type(input, "k");
            expect(onSearchChange).toHaveBeenCalledWith("k");
            expect(onSearch).not.toHaveBeenCalled();

            await user.type(input, "{Enter}");
            expect(onSearch).toHaveBeenCalledTimes(1);
        });

        it("is disabled while searching", () => {
            renderGrid({ isSearching: true });

            expect(screen.getByPlaceholderText(/Cari produk/)).toBeDisabled();
        });
    });
});
