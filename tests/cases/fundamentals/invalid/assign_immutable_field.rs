// error: Cannot assign to field of immutable

struct S { x: i32 }

fn main() {
    let s = S { x: 0 };
    s.x = 1;
}
