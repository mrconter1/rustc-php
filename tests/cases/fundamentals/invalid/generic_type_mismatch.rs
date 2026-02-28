// error: Type mismatch

struct Box<T> {
    value: T,
}

fn take_int_box(b: Box<i32>) -> i32 {
    b.value
}

fn main() {
    let b = Box { value: true };
    take_int_box(b);
}
