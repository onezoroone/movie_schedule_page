import type { Metadata } from "next";
import CalendarClient from "./CalendarClient";

export const metadata: Metadata = {
  title: "Lịch Phim Châu Á",
  description: "Lịch phim châu Á theo giờ Việt Nam, đối chiếu tên Việt từ TMDB.",
};

export default function Home() {
  return <CalendarClient />;
}
